<?php

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const PF = 'https://pixelforge.test/v1';

function client(int $maxAttempts = 4): PixelforgeClient
{
    return new PixelforgeClient(PF, 'pf_key_123', $maxAttempts);
}

function apiError(string $type, string $code, int $status, array $headers = [])
{
    return Http::response(['error' => ['type' => $type, 'code' => $code, 'message' => "msg {$code}"]], $status, $headers);
}

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
});

it('creates a generation with auth, idempotency key and json body', function () {
    Http::fake([PF.'/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

    $result = client()->createGeneration(['prompt' => 'a cat', 'size' => '1024x1024'], 'idem-123');

    expect($result['id'])->toBe('gen_1');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === PF.'/generations'
        && $r->hasHeader('Authorization', 'Bearer pf_key_123')
        && $r->hasHeader('Idempotency-Key', 'idem-123')
        && $r['prompt'] === 'a cat');
});

it('retries an overload and honours Retry-After, backing off further each time', function () {
    Http::fake([PF.'/generations' => Http::sequence()
        ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])
        ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])
        ->push(['id' => 'gen_1'], 202),
    ]);

    $result = client()->createGeneration(['prompt' => 'x'], 'idem-1');

    expect($result['id'])->toBe('gen_1');
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
});

it('waits the Retry-After of a rate limit', function () {
    Http::fake([PF.'/generations' => Http::sequence()
        ->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '7'])
        ->push(['id' => 'gen_1'], 202),
    ]);

    client()->createGeneration(['prompt' => 'x'], 'idem-1');

    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});

it('retries a concurrency limit', function () {
    Http::fake([PF.'/generations' => Http::sequence()
        ->push(['error' => ['code' => 'concurrency_limit_exceeded']], 429, ['Retry-After' => '5'])
        ->push(['id' => 'gen_1'], 202),
    ]);

    expect(client()->createGeneration(['prompt' => 'x'], 'idem-1')['id'])->toBe('gen_1');
});

it('never waits longer than the cap', function () {
    Http::fake([PF.'/generations' => Http::sequence()
        ->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '600'])
        ->push(['id' => 'gen_1'], 202),
    ]);

    client()->createGeneration(['prompt' => 'x'], 'idem-1');

    Sleep::assertSequence([Sleep::for(30)->seconds()]);
});

it('gives up after the maximum attempts instead of retrying forever', function () {
    Http::fake([PF.'/generations' => apiError('api_error', 'overloaded', 503, ['Retry-After' => '2'])]);

    try {
        client(3)->createGeneration(['prompt' => 'x'], 'idem-1');
        $this->fail('Expected an exception.');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe('overloaded')->and($e->retryable())->toBeTrue();
    }

    Http::assertSentCount(3);
});

it('retries a network error with the very same idempotency key', function () {
    Http::fake([PF.'/generations' => Http::sequence()
        ->pushFailedConnection()
        ->push(['id' => 'gen_1'], 202),
    ]);

    client()->createGeneration(['prompt' => 'x'], 'idem-same');

    $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->all();
    expect($keys)->toBe(['idem-same', 'idem-same']);
});

it('reports an unreachable provider after repeated network errors', function () {
    Http::fake([PF.'/generations' => fn () => throw new ConnectionException('boom')]);

    try {
        client(2)->createGeneration(['prompt' => 'x'], 'idem-1');
        $this->fail('Expected an exception.');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe('unreachable');
    }
});

it('does not retry errors that retrying cannot fix', function (string $type, string $code, int $status) {
    Http::fake([PF.'/generations' => apiError($type, $code, $status)]);

    expect(fn () => client()->createGeneration(['prompt' => 'x'], 'idem-1'))
        ->toThrow(fn (PixelforgeException $e) => $e->errorCode === $code && ! $e->retryable());

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'validation' => ['invalid_request_error', 'validation_failed', 422],
    'content policy' => ['invalid_request_error', 'content_policy_violation', 400],
    'bad key' => ['authentication_error', 'invalid_api_key', 401],
    'idempotency reuse' => ['idempotency_error', 'idempotency_key_reused', 409],
    'daily quota' => ['rate_limit_error', 'daily_limit_exceeded', 429],
]);

it('exposes the validation parameter and a user-safe message', function () {
    Http::fake([PF.'/generations' => Http::response(['error' => [
        'type' => 'invalid_request_error',
        'code' => 'validation_failed',
        'message' => 'The selected size is invalid.',
        'param' => 'size',
    ]], 422)]);

    try {
        client()->createGeneration(['prompt' => 'x', 'size' => '1x1'], 'idem-1');
        $this->fail('Expected an exception.');
    } catch (PixelforgeException $e) {
        expect($e->param)->toBe('size')->and($e->userMessage())->toBe('The selected size is invalid.');
    }
});

it('gives friendly messages that never promise a charge', function (string $code) {
    $e = new PixelforgeException('raw', $code, 400);

    expect($e->userMessage())->not->toBe('raw')->and($e->userMessage())->not->toBeEmpty();
})->with(['content_policy_violation', 'overloaded', 'rate_limit_exceeded', 'daily_limit_exceeded', 'something_new']);

it('fetches a generation', function () {
    Http::fake([PF.'/generations/gen_9' => Http::response(['id' => 'gen_9', 'status' => 'succeeded'])]);

    expect(client()->getGeneration('gen_9')['status'])->toBe('succeeded');
});

it('fetches usage for a date range', function () {
    Http::fake([PF.'/usage*' => Http::response(['object' => 'usage', 'data' => []])]);

    client()->usage('2026-11-01', '2026-11-02');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'start_date=2026-11-01')
        && str_contains($r->url(), 'end_date=2026-11-02'));
});

it('reads pricing without credentials and caches it', function () {
    Http::fake([PF.'/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true))]);

    $first = client()->pricingTable();
    client()->pricingTable();

    expect($first->rates->output)->toBe(40_000);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
});
