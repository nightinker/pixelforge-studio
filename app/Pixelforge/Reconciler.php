<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for everything that can go wrong between us and Pixelforge:
 * a webhook that never arrives, a queue job that dies, a request that timed out.
 *
 * It only ever uses operations that are safe to repeat: resubmitting with the
 * same Idempotency-Key, and GenerationSync, which is idempotent and monotone.
 * So it can run as often as we like and can never double-record a cost.
 */
final class Reconciler
{
    /** How long a generation may sit unsent before we assume its job was lost. */
    public const UNSENT_GRACE_SECONDS = 120;

    /** How long an in-flight generation may go without news before we ask Pixelforge. */
    public const POLL_AFTER_SECONDS = 45;

    /** Per run, so a backlog cannot blow through the 60 requests/minute limit. */
    public const BATCH_SIZE = 30;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationSync $sync,
    ) {}

    /** @return array{resubmitted: int, polled: int, errors: int} */
    public function run(): array
    {
        return [
            'resubmitted' => $this->resubmitUnsent(),
            ...$this->pollStale(),
        ];
    }

    /**
     * Generations that never got a provider id. Sending again with the same
     * Idempotency-Key is safe whether or not the first attempt reached Pixelforge:
     * it either creates the generation or returns the one that already exists.
     */
    private function resubmitUnsent(): int
    {
        $unsent = Generation::query()
            ->whereNull('provider_id')
            ->where('status', GenerationStatus::Queued->value)
            ->where('created_at', '<', now()->subSeconds(self::UNSENT_GRACE_SECONDS))
            ->oldest()
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        foreach ($unsent as $id) {
            SubmitGeneration::dispatch($id);
        }

        return $unsent->count();
    }

    /**
     * Sent but not finished, and quiet for a while: ask Pixelforge directly.
     *
     * @return array{polled: int, errors: int}
     */
    private function pollStale(): array
    {
        $stale = Generation::query()
            ->whereNotNull('provider_id')
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->where('updated_at', '<', now()->subSeconds(self::POLL_AFTER_SECONDS))
            ->oldest('updated_at')
            ->limit(self::BATCH_SIZE)
            ->get();

        $polled = 0;
        $errors = 0;

        foreach ($stale as $generation) {
            try {
                $this->sync->apply($this->client->getGeneration($generation->provider_id));
                $polled++;
            } catch (\Throwable $e) {
                // One bad generation must not stop the others from being reconciled.
                $errors++;
                Log::warning('Pixelforge reconcile failed for a generation.', [
                    'generation' => $generation->id,
                    'provider_id' => $generation->provider_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Push it to the back of the queue so one stuck generation cannot
            // starve the rest, whether or not anything changed.
            $generation->touch();
        }

        return ['polled' => $polled, 'errors' => $errors];
    }
}
