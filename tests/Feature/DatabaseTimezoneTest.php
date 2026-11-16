<?php

use App\Models\Generation;

it('stores and reads timestamps as the same instant, whatever the server timezone', function () {
    // Regression: Postgres defaulted to the server's local zone (Asia/Manila here),
    // so UTC wall-clock times written by Laravel were read back 8 hours off.
    $generation = Generation::factory()->create();

    expect(DB::selectOne('show timezone')->TimeZone)->toBe('UTC')
        ->and($generation->refresh()->created_at->diffInSeconds(now(), true))->toBeLessThan(5);
});
