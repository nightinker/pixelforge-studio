<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends a queued generation to Pixelforge.
 *
 * The generation's own id is the Idempotency-Key, so running this twice (a
 * retry, a crash after the request went out, a duplicate job) can never create
 * or bill a second generation: Pixelforge returns the one that already exists.
 */
class SubmitGeneration implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** Pixelforge errors that mean "nothing was created", so the generation can safely be failed. */
    private const NOTHING_CREATED = ['overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded'];

    public function __construct(public readonly string $generationId) {}

    public function handle(PixelforgeClient $client, GenerationSync $sync): void
    {
        $generation = Generation::find($this->generationId);

        // Already sent (or finished): nothing to do. This makes duplicate jobs harmless.
        if ($generation === null || $generation->provider_id !== null || $generation->status->isFinal()) {
            return;
        }

        try {
            $remote = $client->createGeneration($this->payload($generation), $generation->id);
        } catch (PixelforgeException $e) {
            $this->handleRejection($generation, $e);

            return;
        }

        // Links the provider id and applies whatever state Pixelforge is already in
        // (a webhook may have beaten this response, or this may be an idempotent replay).
        $sync->apply($remote);
    }

    /** Last resort for an unexpected error: do not leave the user watching "queued" forever. */
    public function failed(Throwable $e): void
    {
        Generation::failUnsubmitted(
            $this->generationId,
            'submission_error',
            'We could not start this generation. You were not charged.',
        );
    }

    private function handleRejection(Generation $generation, PixelforgeException $e): void
    {
        $finalAttempt = $this->attempts() >= $this->tries;

        if ($e->retryable() && ! $finalAttempt) {
            $this->release(max(5, $e->retryAfter ?? 5));

            return;
        }

        // We cannot tell whether a request that timed out or hit an unknown server
        // error was created. Failing it now could write off a generation that is
        // really running (and billed), so leave it queued: the reconcile command
        // resubmits with the same Idempotency-Key and finds out for sure.
        if ($e->retryable() && ! in_array($e->errorCode, self::NOTHING_CREATED, true)) {
            return;
        }

        Generation::failUnsubmitted($generation->id, $e->errorCode, $e->userMessage());
    }

    /** @return array<string, mixed> */
    private function payload(Generation $generation): array
    {
        return array_filter([
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?: null,
            // Comes back on the generation and on every webhook, so events can be
            // matched to this row even before we know Pixelforge's id.
            'metadata' => ['generation_id' => $generation->id],
        ], fn ($value) => $value !== null);
    }
}
