<?php

use App\Pixelforge\WebhookSignature;

const WH_SECRET = 'whsec_test_secret';
const WH_NOW = 1_762_265_009;

it('accepts a correctly signed payload', function () {
    $header = WebhookSignature::sign('{"a":1}', WH_SECRET, WH_NOW);

    expect(WebhookSignature::verify('{"a":1}', $header, WH_SECRET, WH_NOW))->toBeTrue();
});

it('matches the header format from the docs', function () {
    $header = WebhookSignature::sign('{}', WH_SECRET, WH_NOW);

    expect($header)->toMatch('/^t=1762265009,v1=[0-9a-f]{64}$/');
});

it('rejects a body that differs from the one that was signed', function () {
    $header = WebhookSignature::sign('{"a":1}', WH_SECRET, WH_NOW);

    expect(WebhookSignature::verify('{"a":2}', $header, WH_SECRET, WH_NOW))->toBeFalse();
});

it('rejects a signature made with a different secret', function () {
    $header = WebhookSignature::sign('{"a":1}', 'whsec_other', WH_NOW);

    expect(WebhookSignature::verify('{"a":1}', $header, WH_SECRET, WH_NOW))->toBeFalse();
});

it('rejects a header whose timestamp was changed after signing', function () {
    // The timestamp is part of the signed string, so it cannot be refreshed
    // on a captured request to get around the replay window.
    $header = WebhookSignature::sign('{"a":1}', WH_SECRET, WH_NOW - 1000);
    $refreshed = preg_replace('/^t=\d+/', 't='.WH_NOW, $header);

    expect(WebhookSignature::verify('{"a":1}', $refreshed, WH_SECRET, WH_NOW))->toBeFalse();
});

it('enforces the five minute window in both directions', function (int $age, bool $ok) {
    $header = WebhookSignature::sign('{}', WH_SECRET, WH_NOW - $age);

    expect(WebhookSignature::verify('{}', $header, WH_SECRET, WH_NOW))->toBe($ok);
})->with([
    'fresh' => [0, true],
    'edge' => [300, true],
    'too old' => [301, false],
    'future edge' => [-300, true],
    'too far in the future' => [-301, false],
]);

it('rejects malformed headers', function (?string $header) {
    expect(WebhookSignature::verify('{}', $header, WH_SECRET, WH_NOW))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [''],
    'no signature' => ['t=1762265009'],
    'no timestamp' => ['v1=abcdef'],
    'garbage' => ['hello'],
    'non numeric timestamp' => ['t=abc,v1=abcdef'],
]);

it('never accepts anything while no secret is configured', function () {
    // An empty key is a valid HMAC key, so anyone could "sign" with it.
    $header = WebhookSignature::sign('{}', '', WH_NOW);

    expect(WebhookSignature::verify('{}', $header, '', WH_NOW))->toBeFalse();
});
