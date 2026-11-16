<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

/** Run the job the way a worker would, with its dependencies injected. */
function runSubmit(Generation $generation, ?SubmitGeneration $job = null): SubmitGeneration
{
    $job ??= (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function acceptedGeneration(Generation $generation, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'gen_01SUBMITTEST0000000000',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => $generation->prompt,
        'metadata' => ['generation_id' => $generation->id],
        'usage' => null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => null,
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

it('sends the request with our id as idempotency key and in metadata', function () {
    $generation = Generation::factory()->create([
        'prompt' => 'a lighthouse',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png'],
    ]);
    Http::fake(['*/generations' => Http::response(acceptedGeneration($generation), 202)]);

    runSubmit($generation);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->hasHeader('Idempotency-Key', $generation->id)
        && $r['prompt'] === 'a lighthouse'
        && $r['size'] === '1536x1024'
        && $r['quality'] === 'high'
        && $r['reference_images'] === ['https://example.com/a.png']
        && $r['metadata'] === ['generation_id' => $generation->id]);
});

it('leaves out reference images when there are none', function () {
    $generation = Generation::factory()->create(['reference_images' => []]);
    Http::fake(['*/generations' => Http::response(acceptedGeneration($generation), 202)]);

    runSubmit($generation);

    Http::assertSent(fn (Request $r) => ! array_key_exists('reference_images', $r->data()));
});

it('links the provider id and keeps the generation queued', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(acceptedGeneration($generation), 202)]);

    runSubmit($generation);

    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_01SUBMITTEST0000000000')
        ->and($generation->status)->toBe(GenerationStatus::Queued);
});

it('prices correctly when the replay shows it already finished', function () {
    // The webhook (or an idempotent replay) can be ahead of our create response.
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(acceptedGeneration($generation, [
        'status' => 'succeeded',
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => '2026-11-04T14:03:29Z',
        'image_url' => 'https://pixelforge.test/i.png',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
    ]), 202)]);

    runSubmit($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nano)->toBe(381_887_500);
});

it('does nothing for a generation that was already sent', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_ALREADY']);
    Http::fake();

    runSubmit($generation);

    Http::assertNothingSent();
});

it('does nothing for a generation that is already final', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Failed]);
    Http::fake();

    runSubmit($generation);

    Http::assertNothingSent();
});

it('fails the generation, free of charge, when the prompt is rejected', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(['error' => [
        'type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'rejected',
    ]], 400)]);

    runSubmit($generation);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->error_message)->toContain('content policy')
        ->and($generation->charge_nano)->toBeNull();
    Http::assertSentCount(1);
});

it('puts the job back on the queue when Pixelforge is overloaded', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])]);

    $job = runSubmit($generation);

    $job->assertReleased(5);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('fails an overloaded generation only after the last attempt', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])]);

    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->tries = 1; // this is the last attempt

    runSubmit($generation, $job);

    $job->assertNotReleased();
    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_message)->toContain('busy')
        ->and($generation->charge_nano)->toBeNull();
});

it('does not fail a generation it cannot be sure was never created', function () {
    // A timeout after the request left may still have created the generation.
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);

    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->tries = 1;

    runSubmit($generation, $job);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->error_code)->toBeNull();
});

it('fails the generation if the job itself blows up', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation->id))->failed(new RuntimeException('boom'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submission_error');
});

it('will not fail a generation Pixelforge has already taken', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_TAKEN']);

    expect(Generation::failUnsubmitted($generation->id, 'x', 'y'))->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
