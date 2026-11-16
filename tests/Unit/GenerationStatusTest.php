<?php

use App\Enums\GenerationStatus as S;

it('only ever moves forward', function (S $from, S $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    'queued -> processing' => [S::Queued, S::Processing, true],
    'queued -> succeeded (processing event lost)' => [S::Queued, S::Succeeded, true],
    'queued -> failed' => [S::Queued, S::Failed, true],
    'processing -> succeeded' => [S::Processing, S::Succeeded, true],
    'processing -> failed' => [S::Processing, S::Failed, true],
    'late processing after succeeded' => [S::Succeeded, S::Processing, false],
    'late processing after failed' => [S::Failed, S::Processing, false],
    'late queued after processing' => [S::Processing, S::Queued, false],
    'succeeded -> failed' => [S::Succeeded, S::Failed, false],
    'failed -> succeeded' => [S::Failed, S::Succeeded, false],
    'same status again (duplicate event)' => [S::Processing, S::Processing, false],
    'succeeded again' => [S::Succeeded, S::Succeeded, false],
]);

it('knows which statuses are final', function () {
    expect(S::Succeeded->isFinal())->toBeTrue()
        ->and(S::Failed->isFinal())->toBeTrue()
        ->and(S::Queued->isFinal())->toBeFalse()
        ->and(S::Processing->isFinal())->toBeFalse();
});
