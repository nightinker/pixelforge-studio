<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;

const WH_SIGNING_SECRET = 'whsec_feature_test';

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => WH_SIGNING_SECRET]);
});

/** A generation object as Pixelforge sends it. */
function remoteGeneration(string $status, array $overrides = []): array
{
    $base = [
        'id' => 'gen_01TESTGENERATION00000000',
        'object' => 'generation',
        'status' => $status,
        'prompt' => 'a lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ];

    if ($status !== 'queued') {
        $base['started_at'] = '2026-11-04T14:03:13Z';
    }

    if ($status === 'succeeded') {
        $base['completed_at'] = '2026-11-04T14:03:29Z';
        $base['image_url'] = 'https://pixelforge.promptedit.dev/images/gen.png?sig=abc';
        $base['image_url_expires_at'] = '2026-11-05T14:03:29Z';
        // 12*5 + 765*10 + 6450*40 + 3184*12.5 = $0.30551
        $base['usage'] = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];
    }

    if ($status === 'failed') {
        $base['completed_at'] = '2026-11-04T14:03:20Z';
        $base['error'] = ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'];
    }

    return array_replace_recursive($base, $overrides);
}

function webhookEvent(string $id, string $type, ?array $generation): array
{
    return [
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

/** POST an event, signed the way Pixelforge signs it. */
function deliver($test, array $event, ?string $secret = WH_SIGNING_SECRET, ?int $signedAt = null)
{
    $body = json_encode($event);
    $header = WebhookSignature::sign($body, $secret ?? '', $signedAt ?? time());

    return $test->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $header,
    ], $body);
}

function localGeneration(array $attributes = []): Generation
{
    return Generation::factory()->create(['provider_id' => 'gen_01TESTGENERATION00000000'] + $attributes);
}

// ---------------------------------------------------------------- happy path

it('records a succeeded generation and its exact cost', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_1', 'generation.succeeded', remoteGeneration('succeeded')))
        ->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano)->toBe(305_510_000)   // $0.30551
        ->and($generation->charge_nano)->toBe(381_887_500)          // + 25%
        ->and($generation->isSettled())->toBeTrue()
        ->and($generation->image_url)->toContain('gen.png')
        ->and($generation->image_url_expires_at)->not->toBeNull()
        ->and($generation->completed_at)->not->toBeNull();

    expect(WebhookEvent::where('event_id', 'evt_1')->first()->processed_at)->not->toBeNull();
});

it('follows the normal lifecycle', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_p', 'generation.processing', remoteGeneration('processing')))->assertNoContent();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at)->not->toBeNull()
        ->and($generation->isSettled())->toBeFalse();

    deliver($this, webhookEvent('evt_s', 'generation.succeeded', remoteGeneration('succeeded')))->assertNoContent();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nano)->toBe(381_887_500);
});

it('prices from the rates on the generation, not from today\'s rates', function () {
    $generation = localGeneration();

    // Output was $50 per 1M tokens when this generation was created.
    deliver($this, webhookEvent('evt_r', 'generation.succeeded', remoteGeneration('succeeded', [
        'rates' => ['output_tokens' => '50.00'],
    ])))->assertNoContent();

    // 12*5000 + 765*10000 + 6450*50000 + 3184*12500
    expect($generation->refresh()->provider_cost_nano)->toBe(370_010_000);
});

it('does not charge for a failed generation', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_f', 'generation.failed', remoteGeneration('failed')))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('internal_error')
        ->and($generation->charge_nano)->toBeNull()
        ->and($generation->provider_cost_nano)->toBeNull()
        ->and($generation->isSettled())->toBeFalse();
});

// ------------------------------------------------- duplicates and ordering

it('records a duplicated event only once', function () {
    $generation = localGeneration();
    $event = webhookEvent('evt_dup', 'generation.succeeded', remoteGeneration('succeeded'));

    deliver($this, $event)->assertNoContent();
    $settledAt = $generation->refresh()->settled_at;

    $this->travel(5)->seconds();
    deliver($this, $event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and($generation->refresh()->charge_nano)->toBe(381_887_500)
        ->and($generation->settled_at->equalTo($settledAt))->toBeTrue();
});

it('never re-prices on a different event for an already succeeded generation', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_a', 'generation.succeeded', remoteGeneration('succeeded')))->assertNoContent();

    // A second succeeded event with a different id and (hypothetically) different usage.
    deliver($this, webhookEvent('evt_b', 'generation.succeeded', remoteGeneration('succeeded', [
        'usage' => ['output_tokens' => 99_999],
    ])))->assertNoContent();

    expect(WebhookEvent::count())->toBe(2)
        ->and($generation->refresh()->charge_nano)->toBe(381_887_500);
});

it('ignores a late processing event after success', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_s', 'generation.succeeded', remoteGeneration('succeeded')))->assertNoContent();
    $startedAt = $generation->refresh()->started_at;

    deliver($this, webhookEvent('evt_late', 'generation.processing', remoteGeneration('processing', [
        'started_at' => '2026-11-04T14:09:00Z',
    ])))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->started_at->equalTo($startedAt))->toBeTrue()
        ->and($generation->charge_nano)->toBe(381_887_500);
});

it('ignores a late processing event after failure', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_f', 'generation.failed', remoteGeneration('failed')))->assertNoContent();
    deliver($this, webhookEvent('evt_late', 'generation.processing', remoteGeneration('processing')))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
});

it('does not let a conflicting final event change a final status', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_s', 'generation.succeeded', remoteGeneration('succeeded')))->assertNoContent();
    deliver($this, webhookEvent('evt_f', 'generation.failed', remoteGeneration('failed')))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->error_code)->toBeNull();
});

it('succeeds directly even when the processing event never arrived', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_s', 'generation.succeeded', remoteGeneration('succeeded')))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

// ------------------------------------------- arriving before we know the id

it('matches an event that arrives before the create call returned', function () {
    // We have our own row, but have not yet stored Pixelforge's id.
    $generation = Generation::factory()->create(['provider_id' => null]);

    deliver($this, webhookEvent('evt_early', 'generation.succeeded', remoteGeneration('succeeded', [
        'metadata' => ['generation_id' => $generation->id],
    ])))->assertNoContent();

    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_01TESTGENERATION00000000')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nano)->toBe(381_887_500);
});

it('does not move a generation that already has a different provider id', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_SOMETHING_ELSE']);

    deliver($this, webhookEvent('evt_x', 'generation.succeeded', remoteGeneration('succeeded', [
        'metadata' => ['generation_id' => $generation->id],
    ])))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_SOMETHING_ELSE');
});

it('acknowledges events for generations that are not ours', function () {
    deliver($this, webhookEvent('evt_ghost', 'generation.succeeded', remoteGeneration('succeeded')))->assertNoContent();

    expect(Generation::count())->toBe(0)
        ->and(WebhookEvent::where('event_id', 'evt_ghost')->first()->processed_at)->not->toBeNull();
});

it('acknowledges a ping', function () {
    deliver($this, webhookEvent('evt_ping', 'ping', null))->assertNoContent();

    expect(WebhookEvent::where('event_id', 'evt_ping')->first()->processed_at)->not->toBeNull();
});

// ------------------------------------------------------------- retry safety

it('answers with an error and keeps the event unprocessed when it cannot be priced', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_bad', 'generation.succeeded', remoteGeneration('succeeded', ['usage' => null])))
        ->assertServerError();

    expect(WebhookEvent::where('event_id', 'evt_bad')->first()->processed_at)->toBeNull()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->isSettled())->toBeFalse();
});

it('finishes an event that was received earlier but never processed', function () {
    $generation = localGeneration();
    $event = webhookEvent('evt_retry', 'generation.succeeded', remoteGeneration('succeeded'));

    WebhookEvent::create([
        'event_id' => 'evt_retry',
        'type' => 'generation.succeeded',
        'payload' => $event,
        'received_at' => now(),
    ]);

    deliver($this, $event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->processed_at)->not->toBeNull()
        ->and($generation->refresh()->charge_nano)->toBe(381_887_500);
});

// ---------------------------------------------------------------- security

it('rejects a request with a wrong signature and stores nothing', function () {
    $generation = localGeneration();

    deliver($this, webhookEvent('evt_1', 'generation.succeeded', remoteGeneration('succeeded')), 'whsec_wrong')
        ->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects a request with no signature header', function () {
    $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"evt_1","type":"ping"}')
        ->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a body that was changed after signing', function () {
    localGeneration();
    $body = json_encode(webhookEvent('evt_1', 'generation.succeeded', remoteGeneration('succeeded')));
    $header = WebhookSignature::sign($body, WH_SIGNING_SECRET, time());

    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $header,
    ], str_replace('6450', '1', $body))->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a replayed old request', function () {
    deliver($this, webhookEvent('evt_old', 'ping', null), WH_SIGNING_SECRET, time() - 400)
        ->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects everything when no signing secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    deliver($this, webhookEvent('evt_1', 'ping', null), '')->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a validly signed body that is not an event', function () {
    $body = '{"hello":"world"}';

    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, WH_SIGNING_SECRET, time()),
    ], $body)->assertStatus(400);
});
