<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
});

function fakePricing(): void
{
    Http::fake(['*/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true))]);
}

function validInput(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'a lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ], $overrides);
}

// ----------------------------------------------------------------- access

it('keeps guests out', function (string $method, string $uri) {
    $this->{$method}($uri)->assertRedirect('/login');
})->with([
    ['get', '/generate'],
    ['get', '/generations'],
    ['get', '/generations/estimate'],
    ['post', '/generations'],
]);

it('sends the dashboard to the generate page', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/generate');
});

it('renders the generate page', function () {
    $this->actingAs(User::factory()->create())->get('/generate')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Create')
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high'])
            ->where('markupPercent', 25));
});

// --------------------------------------------------------------- estimate

it('estimates a price using the same math as billing', function () {
    fakePricing();

    // The worked example from the API docs: high, 1536x1024, 400 chars, 2 images.
    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?'.http_build_query([
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_count' => 2,
        ]))
        ->assertOk()
        ->assertJson(['max' => '$0.46734375']);   // $0.373875 + 25%
});

it('estimates an empty prompt rather than erroring', function () {
    fakePricing();

    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?size=1024x1024&quality=low')
        ->assertOk()
        ->assertJsonStructure(['expected', 'min', 'max']);
});

it('rejects an invalid size in the estimate', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?size=1x1&quality=low')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('size');
});

it('says so plainly when prices cannot be fetched', function () {
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?size=1024x1024&quality=low')
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'busy'));
});

// ------------------------------------------------------------------ store

it('records the generation with its estimate and queues it', function () {
    Queue::fake();
    fakePricing();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', validInput())
        ->assertRedirect('/generations');

    $generation = $user->generations()->sole();

    // 5 text + 1100 output + midpoint reasoning 1100 => $0.057775, +25%
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->estimated_charge_nano)->toBe(72_218_750)
        ->and($generation->estimated_max_charge_nano)->toBe(86_287_500)
        ->and($generation->prompt)->toBe('a lighthouse at dawn');

    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
});

it('stores reference images in order', function () {
    Queue::fake();
    fakePricing();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', validInput([
        'reference_images' => ['https://example.com/a.png', 'http://example.com/b.jpg'],
    ]))->assertRedirect();

    expect($user->generations()->sole()->reference_images)
        ->toBe(['https://example.com/a.png', 'http://example.com/b.jpg']);
});

it('ignores a price sent by the browser', function () {
    Queue::fake();
    fakePricing();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', validInput(['estimated_charge_nano' => 1]))->assertRedirect();

    expect($user->generations()->sole()->estimated_charge_nano)->toBe(72_218_750);
});

it('rejects invalid input and records nothing', function (array $override, string $errorKey) {
    Queue::fake();
    fakePricing();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', validInput($override))->assertSessionHasErrors($errorKey);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'bad size' => [['size' => '10x10'], 'size'],
    'bad quality' => [['quality' => 'ultra'], 'quality'],
    'five references' => [['reference_images' => array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5))], 'reference_images'],
    'not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'ftp url' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'duplicate urls' => [['reference_images' => ['https://example.com/a.png', 'https://example.com/a.png']], 'reference_images.0'],
]);

it('records nothing when prices cannot be fetched', function () {
    Queue::fake();
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    $this->actingAs(User::factory()->create())->post('/generations', validInput())
        ->assertSessionHasErrors('form');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- history

it('lists only my generations, newest first', function () {
    $me = User::factory()->create();
    $older = Generation::factory()->for($me)->create(['prompt' => 'older', 'created_at' => now()->subHour()]);
    $newer = Generation::factory()->for($me)->create(['prompt' => 'newer']);
    Generation::factory()->create(['prompt' => 'someone else']);

    $this->actingAs($me)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Index')
            ->has('generations', 2)
            ->where('generations.0.id', $newer->id)
            ->where('generations.1.id', $older->id));
});

it('shows estimated and actual cost for a succeeded generation', function () {
    $me = User::factory()->create();
    Generation::factory()->for($me)->succeeded()->create([
        'provider_cost_nano' => 305_510_000,
        'charge_nano' => 381_887_500,
        'settled_at' => now(),
        'image_url_expires_at' => now()->addHours(20),
    ]);

    $this->actingAs($me)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.status', 'succeeded')
            ->where('generations.0.is_final', true)
            ->where('generations.0.charge', '$0.3818875')
            ->where('generations.0.estimated_charge', '$0.10')
            ->where('generations.0.image_expired', false)
            ->whereNot('generations.0.image_url', null));
});

it('shows a failed generation as free, with the reason', function () {
    $me = User::factory()->create();
    Generation::factory()->for($me)->create([
        'status' => GenerationStatus::Failed,
        'error_message' => 'Something went wrong. You were not charged.',
    ]);

    $this->actingAs($me)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.charge', null)
            ->where('generations.0.error_message', 'Something went wrong. You were not charged.'));
});

it('hides an expired image instead of showing a broken one', function () {
    $me = User::factory()->create();
    Generation::factory()->for($me)->succeeded()->create(['image_url_expires_at' => now()->subHour()]);

    $this->actingAs($me)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.image_url', null)
            ->where('generations.0.image_expired', true));
});

it('marks in-flight generations as not final', function () {
    $me = User::factory()->create();
    Generation::factory()->for($me)->create(['status' => GenerationStatus::Processing]);

    $this->actingAs($me)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.status', 'processing')
            ->where('generations.0.is_final', false)
            ->where('generations.0.charge', null));
});
