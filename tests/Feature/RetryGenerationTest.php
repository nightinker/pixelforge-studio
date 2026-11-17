<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Queue::fake();
    Sleep::fake();
    Cache::flush();
});

function stubPricing(): void
{
    Http::fake(['*/pricing' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true))]);
}

function failedGeneration(User $user, array $attributes = []): Generation
{
    return Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Failed,
        'error_code' => 'invalid_api_key',
        'error_message' => 'Something went wrong while contacting the image provider. Nothing was charged.',
        'prompt' => 'A tiny red paper boat',
        'size' => '1536x1024',
        'quality' => 'low',
        'reference_images' => ['https://example.test/a.png'],
    ] + $attributes);
}

it('retries a failed generation as a brand new one with the same inputs', function () {
    stubPricing();
    $user = User::factory()->create();
    $failed = failedGeneration($user);

    $this->actingAs($user)
        ->post(route('generations.retry', $failed->id))
        ->assertRedirect(route('generations.index'));

    $new = Generation::where('id', '!=', $failed->id)->sole();

    expect($new->user_id)->toBe($user->id)
        ->and($new->prompt)->toBe('A tiny red paper boat')
        ->and($new->size)->toBe('1536x1024')
        ->and($new->quality)->toBe('low')
        ->and($new->reference_images)->toBe(['https://example.test/a.png'])
        ->and($new->status)->toBe(GenerationStatus::Queued)
        ->and($new->provider_id)->toBeNull()
        ->and($new->estimated_charge_nano)->toBeGreaterThan(0);

    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $new->id);
});

it('leaves the failed generation untouched in the history', function () {
    stubPricing();
    $user = User::factory()->create();
    $failed = failedGeneration($user);

    $this->actingAs($user)->post(route('generations.retry', $failed->id));

    expect($failed->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($failed->error_code)->toBe('invalid_api_key');
});

it('uses a different idempotency identity for the retry', function () {
    stubPricing();
    $user = User::factory()->create();
    $failed = failedGeneration($user);

    $this->actingAs($user)->post(route('generations.retry', $failed->id));

    // The generation id is the Idempotency-Key, so a retry must never reuse it.
    expect(Generation::where('id', '!=', $failed->id)->sole()->id)->not->toBe($failed->id);
});

it('refuses to retry a generation that has not failed', function (GenerationStatus $status) {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create(['status' => $status]);

    $this->actingAs($user)->post(route('generations.retry', $generation->id))->assertStatus(409);

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
})->with([
    'queued' => GenerationStatus::Queued,
    'processing' => GenerationStatus::Processing,
    'succeeded' => GenerationStatus::Succeeded,
]);

it('cannot retry someone else\'s generation', function () {
    $owner = User::factory()->create();
    $failed = failedGeneration($owner);

    $this->actingAs(User::factory()->create())
        ->post(route('generations.retry', $failed->id))
        ->assertNotFound();

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('requires a signed-in user', function () {
    $failed = failedGeneration(User::factory()->create());

    $this->post(route('generations.retry', $failed->id))->assertRedirect(route('login'));
});

it('reports a clear error when prices cannot be fetched, and creates nothing', function () {
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);
    $user = User::factory()->create();
    $failed = failedGeneration($user);

    $this->actingAs($user)
        ->from(route('generations.index'))
        ->post(route('generations.retry', $failed->id))
        ->assertRedirect(route('generations.index'))
        ->assertSessionHasErrors('retry');

    expect(Generation::count())->toBe(1);
});

it('shows the provider error code on a failed generation', function () {
    $user = User::factory()->create();
    failedGeneration($user);

    $this->actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Generations/Index')
            ->where('generations.0.error_code', 'invalid_api_key')
            ->where('generations.0.status', 'failed'));
});

it('does not expose an error code for generations that did not fail', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create(['status' => GenerationStatus::Processing, 'error_code' => 'stale']);

    $this->actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn ($page) => $page->where('generations.0.error_code', null));
});
