<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\CostCalculator;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings a local generation in line with what Pixelforge says about it.
 *
 * This is the single place that changes a generation's status or records what
 * it cost, and webhooks and polling both go through it. It is safe to call any
 * number of times, in any order, with the same or different snapshots:
 *
 *  - the row is locked, so concurrent calls run one after another;
 *  - a status only ever moves forward (see GenerationStatus::canMoveTo), so a
 *    duplicate or late event is a no-op;
 *  - the price is written only in the same update that moves a generation to
 *    `succeeded`, from the rates and usage of that very snapshot, so it can
 *    be recorded at most once.
 */
final class GenerationSync
{
    public function __construct(private readonly CostCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $remote  a Pixelforge generation object
     * @return Generation|null the local generation, or null if it is not ours
     */
    public function apply(array $remote): ?Generation
    {
        return DB::transaction(function () use ($remote) {
            $generation = $this->lockLocal($remote);

            if ($generation === null) {
                return null;
            }

            $updates = [];

            // A webhook can arrive before our create request has returned, so we
            // may only learn the provider id from the event itself.
            if ($generation->provider_id === null) {
                $updates['provider_id'] = $remote['id'];
            }

            $next = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''));

            if ($next !== null && $generation->status->canMoveTo($next)) {
                $updates += ['status' => $next] + match ($next) {
                    GenerationStatus::Queued => [],
                    GenerationStatus::Processing => $this->processingFields($remote),
                    GenerationStatus::Succeeded => $this->succeededFields($remote),
                    GenerationStatus::Failed => $this->failedFields($remote),
                };
            }

            if ($updates !== []) {
                $generation->update($updates);
            }

            return $generation;
        });
    }

    /** Find our row by provider id, else by the local id we put in metadata, and lock it. */
    private function lockLocal(array $remote): ?Generation
    {
        $providerId = $remote['id'] ?? null;

        if (! is_string($providerId) || $providerId === '') {
            return null;
        }

        $generation = Generation::query()->where('provider_id', $providerId)->lockForUpdate()->first();

        if ($generation !== null) {
            return $generation;
        }

        $localId = $remote['metadata']['generation_id'] ?? null;

        if (! is_string($localId) || ! Str::isUuid($localId)) {
            return null;
        }

        $generation = Generation::query()->whereKey($localId)->lockForUpdate()->first();

        // Never re-link a generation that already belongs to a different provider id.
        return $generation?->provider_id === null ? $generation : null;
    }

    /** @return array<string, mixed> */
    private function processingFields(array $remote): array
    {
        return ['started_at' => $this->time($remote['started_at'] ?? null)];
    }

    /** @return array<string, mixed> */
    private function failedFields(array $remote): array
    {
        return [
            'started_at' => $this->time($remote['started_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            'error_code' => $remote['error']['code'] ?? null,
            'error_message' => $remote['error']['message'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function succeededFields(array $remote): array
    {
        $usage = $remote['usage'] ?? null;
        $rates = $remote['rates'] ?? null;

        // A success without usage cannot be priced. Fail loudly (the webhook is
        // answered with an error and retried) rather than record a wrong cost.
        if (! is_array($usage) || ! is_array($rates)) {
            throw new RuntimeException("Succeeded generation {$remote['id']} has no usage or rates.");
        }

        $settlement = $this->calculator->settle(TokenUsage::fromApi($usage), Rates::fromApi($rates));

        return [
            'started_at' => $this->time($remote['started_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            'usage' => $usage,
            'rates' => $rates,
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'provider_cost_nano' => $settlement->providerCost,
            'charge_nano' => $settlement->charge,
            'settled_at' => now(),
        ];
    }

    private function time(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
