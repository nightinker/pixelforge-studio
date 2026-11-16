<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSync $sync): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();

        if (! WebhookSignature::verify(
            $payload,
            $request->header('Pixelforge-Signature'),
            (string) config('services.pixelforge.webhook_secret'),
            time(),
        )) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            abort(400, 'Malformed event.');
        }

        $remote = $event['data']['object'] ?? null;
        $remote = is_array($remote) ? $remote : null;

        // The unique event id makes a second delivery of the same event a no-op here.
        WebhookEvent::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'provider_generation_id' => is_string($remote['id'] ?? null) ? $remote['id'] : null,
            'payload' => $payload,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = WebhookEvent::query()->where('event_id', $event['id'])->firstOrFail();

        // Work is quick (a few indexed queries), so it is done before answering
        // instead of in a queue. If it throws, Pixelforge gets an error and
        // retries, and the event is still unprocessed, so the retry finishes
        // the job. Processing is idempotent, so a repeat can never double-count.
        if ($record->processed_at === null) {
            if ($remote !== null && str_starts_with($event['type'], 'generation.')) {
                $sync->apply($remote);
            }

            $record->update(['processed_at' => now()]);
        }

        return response()->noContent();
    }
}
