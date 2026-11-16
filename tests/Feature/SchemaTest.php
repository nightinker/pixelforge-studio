<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;

it('stores a generation with typed attributes', function () {
    $user = User::factory()->create();

    $generation = Generation::factory()->for($user)->create([
        'reference_images' => ['https://example.com/a.png'],
    ])->fresh();

    expect($generation->id)->toBeString()->toHaveLength(36)
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->reference_images)->toBe(['https://example.com/a.png'])
        ->and($generation->estimated_charge_nano)->toBeInt()
        ->and($generation->isSettled())->toBeFalse()
        ->and($user->generations)->toHaveCount(1);
});

it('keeps a nanodollar amount exactly, even a very large one', function () {
    $generation = Generation::factory()->succeeded()->create([
        'provider_cost_nano' => 4_611_686_018_427_387_903,
        'charge_nano' => 4_611_686_018_427_387_904,
        'settled_at' => now(),
    ])->fresh();

    expect($generation->provider_cost_nano)->toBe(4_611_686_018_427_387_903)
        ->and($generation->charge_nano)->toBe(4_611_686_018_427_387_904);
});

it('refuses two generations with the same provider id', function () {
    Generation::factory()->create(['provider_id' => 'gen_DUPLICATE']);
    Generation::factory()->create(['provider_id' => 'gen_DUPLICATE']);
})->throws(QueryException::class);

it('allows many generations that have no provider id yet', function () {
    Generation::factory()->count(3)->create(['provider_id' => null]);

    expect(Generation::count())->toBe(3);
});

it('refuses the same webhook event twice', function () {
    $event = [
        'event_id' => 'evt_ABC',
        'type' => 'generation.succeeded',
        'payload' => ['id' => 'evt_ABC'],
        'received_at' => now(),
    ];

    WebhookEvent::create($event);
    WebhookEvent::create($event);
})->throws(QueryException::class);

it('refuses a half-settled generation', function () {
    // A charge with no provider cost would corrupt every report.
    Generation::factory()->succeeded()->create([
        'charge_nano' => 100,
        'settled_at' => now(),
    ]);
})->throws(QueryException::class);

it('refuses to price a generation that did not succeed', function (GenerationStatus $status) {
    Generation::factory()->create([
        'status' => $status,
        'provider_cost_nano' => 100,
        'charge_nano' => 125,
        'settled_at' => now(),
    ]);
})->with([GenerationStatus::Queued, GenerationStatus::Processing, GenerationStatus::Failed])
    ->throws(QueryException::class);

it('accepts a fully settled succeeded generation', function () {
    $generation = Generation::factory()->succeeded()->create([
        'provider_cost_nano' => 305_510_000,
        'charge_nano' => 381_887_500,
        'settled_at' => now(),
    ]);

    expect($generation->fresh()->isSettled())->toBeTrue();
});
