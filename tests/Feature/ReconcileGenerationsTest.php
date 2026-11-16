<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\Reconciler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

/** A generation that Pixelforge has accepted but we have heard nothing about for a while. */
function quietGeneration(array $attributes = []): Generation
{
    $generation = Generation::factory()->create([
        'provider_id' => 'gen_QUIET'.fake()->unique()->numerify('######'),
        'status' => GenerationStatus::Processing,
    ] + $attributes);

    // updated_at is managed by Eloquent, so set it directly.
    Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(5)]);

    return $generation->refresh();
}

function remoteState(string $providerId, string $status, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $providerId,
        'object' => 'generation',
        'status' => $status,
        'metadata' => [],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => $status === 'succeeded'
            ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411]
            : null,
        'error' => $status === 'failed' ? ['code' => 'internal_error', 'message' => 'Something went wrong.'] : null,
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/i.png' : null,
        'image_url_expires_at' => $status === 'succeeded' ? '2026-11-17T10:00:00Z' : null,
        'started_at' => '2026-11-16T10:00:00Z',
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-16T10:00:20Z' : null,
    ], $overrides);
}

// -------------------------------------------------------- unsent generations

it('resubmits a generation whose job was lost', function () {
    Queue::fake();
    $lost = Generation::factory()->create(['created_at' => now()->subMinutes(5)]);

    $result = app(Reconciler::class)->run();

    expect($result['resubmitted'])->toBe(1);
    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $lost->id);
});

it('leaves a brand new generation to its own job', function () {
    Queue::fake();
    Generation::factory()->create(['created_at' => now()->subSeconds(30)]);

    app(Reconciler::class)->run();

    Queue::assertNothingPushed();
});

it('does not resubmit what was already sent or already finished', function () {
    Queue::fake();
    Generation::factory()->create(['provider_id' => 'gen_SENT', 'created_at' => now()->subHour()]);
    Generation::factory()->create(['status' => GenerationStatus::Failed, 'created_at' => now()->subHour()]);
    Http::fake(['*' => Http::response(remoteState('gen_SENT', 'queued'))]);

    app(Reconciler::class)->run();

    Queue::assertNothingPushed();
});

it('makes the submit job unique per generation', function () {
    $job = new SubmitGeneration('abc');

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('abc');
});

// ------------------------------------------------------------ lost webhooks

it('finishes a generation whose success webhook never arrived', function () {
    $generation = quietGeneration();
    Http::fake(['*/generations/'.$generation->provider_id => Http::response(remoteState($generation->provider_id, 'succeeded'))]);

    $result = app(Reconciler::class)->run();

    $generation->refresh();

    expect($result['polled'])->toBe(1)
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano)->toBe(305_510_000)
        ->and($generation->charge_nano)->toBe(381_887_500);
});

it('finishes a generation whose failure webhook never arrived, without charging', function () {
    $generation = quietGeneration();
    Http::fake(['*/generations/'.$generation->provider_id => Http::response(remoteState($generation->provider_id, 'failed'))]);

    app(Reconciler::class)->run();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charge_nano)->toBeNull()
        ->and($generation->isSettled())->toBeFalse();
});

it('prices a polled success only once, however often it runs', function () {
    $generation = quietGeneration();
    Http::fake(['*/generations/'.$generation->provider_id => Http::response(remoteState($generation->provider_id, 'succeeded'))]);

    app(Reconciler::class)->run();
    $settledAt = $generation->refresh()->settled_at;

    $this->travel(5)->minutes();
    app(Reconciler::class)->run();
    app(Reconciler::class)->run();

    expect($generation->refresh()->charge_nano)->toBe(381_887_500)
        ->and($generation->settled_at->equalTo($settledAt))->toBeTrue();
    // Final generations are not asked about again.
    Http::assertSentCount(1);
});

it('does not overwrite a webhook that already finished the generation', function () {
    // The poll response is stale: webhook succeeded in the meantime.
    $generation = quietGeneration();
    Http::fake(['*/generations/'.$generation->provider_id => function () use ($generation) {
        Generation::whereKey($generation->id)->update([
            'status' => 'succeeded', 'provider_cost_nano' => 1, 'charge_nano' => 1, 'settled_at' => now(),
        ]);

        return Http::response(remoteState($generation->provider_id, 'processing'));
    }]);

    app(Reconciler::class)->run();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nano)->toBe(1);
});

it('leaves a generation that is still running as it is', function () {
    $generation = quietGeneration();
    Http::fake(['*/generations/'.$generation->provider_id => Http::response(remoteState($generation->provider_id, 'processing'))]);

    app(Reconciler::class)->run();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->isSettled())->toBeFalse();
});

it('does not ask about generations it has recently heard about', function () {
    Http::fake();
    Generation::factory()->create(['provider_id' => 'gen_FRESH', 'status' => GenerationStatus::Processing]);

    app(Reconciler::class)->run();

    Http::assertNothingSent();
});

// --------------------------------------------------------------- resilience

it('carries on when one generation cannot be fetched', function () {
    $broken = quietGeneration();
    $fine = quietGeneration();
    Generation::whereKey($fine->id)->update(['updated_at' => now()->subMinutes(4)]);

    Http::fake([
        '*/generations/'.$broken->provider_id => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation']], 404),
        '*/generations/'.$fine->provider_id => Http::response(remoteState($fine->provider_id, 'succeeded')),
    ]);

    $result = app(Reconciler::class)->run();

    expect($result['errors'])->toBe(1)
        ->and($result['polled'])->toBe(1)
        ->and($fine->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($broken->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('moves a generation it could not fetch to the back of the line', function () {
    $broken = quietGeneration();
    Http::fake(['*' => Http::response(['error' => ['code' => 'not_found']], 404)]);

    app(Reconciler::class)->run();

    // Not stale any more, so a second run does not hammer the same failing id.
    // (Compared in SQL, like the reconciler does, not through a parsed Carbon value.)
    expect(Generation::whereKey($broken->id)->where('updated_at', '>', now()->subMinute())->exists())->toBeTrue();
    Http::fake();
    app(Reconciler::class)->run();
    Http::assertNothingSent();
});

it('never polls more than a batch per run', function () {
    Generation::factory()->count(Reconciler::BATCH_SIZE + 5)->create([
        'provider_id' => null, 'status' => GenerationStatus::Queued, 'created_at' => now()->subHour(),
    ]);
    Queue::fake();

    expect(app(Reconciler::class)->run()['resubmitted'])->toBe(Reconciler::BATCH_SIZE);
});

// ----------------------------------------------------------------- command

it('runs from the command line and reports what it did', function () {
    Queue::fake();
    Generation::factory()->create(['created_at' => now()->subMinutes(10)]);

    $this->artisan('generations:reconcile')
        ->expectsOutput('Resubmitted 1, polled 0, errors 0.')
        ->assertSuccessful();
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'generations:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
