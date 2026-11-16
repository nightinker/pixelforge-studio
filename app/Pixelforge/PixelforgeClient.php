<?php

namespace App\Pixelforge;

use App\Pricing\PricingTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Thin client for the Pixelforge API.
 *
 * Every call is retried when it is safe and useful to: rate limits, concurrency
 * limits, overloads and network errors. The wait honours Retry-After and grows
 * on each attempt, and the number of attempts is capped. Creating a generation
 * always carries an Idempotency-Key, so a retry after a timeout can never
 * create (or bill) a second generation.
 */
final class PixelforgeClient
{
    private const PRICING_CACHE_KEY = 'pixelforge.pricing';

    private const PRICING_TTL_SECONDS = 300;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $maxAttempts = 4,
        private readonly int $maxWaitSeconds = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $params  prompt, size, quality, reference_images, metadata
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send('post', '/generations', $params, ['Idempotency-Key' => $idempotencyKey]);
    }

    /** @return array<string, mixed> the generation object */
    public function getGeneration(string $providerId): array
    {
        return $this->send('get', "/generations/{$providerId}");
    }

    /**
     * What Pixelforge billed this API key per UTC day (inclusive dates, YYYY-MM-DD).
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send('get', '/usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /** Current rates and token tables. Public endpoint, cached briefly. */
    public function pricingTable(): PricingTable
    {
        $response = Cache::remember(
            self::PRICING_CACHE_KEY,
            self::PRICING_TTL_SECONDS,
            fn () => $this->send('get', '/pricing', authenticated: false),
        );

        return PricingTable::fromApi($response);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = [], array $headers = [], bool $authenticated = true): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->request($headers, $authenticated)->{$method}($this->baseUrl.$path, $data);
            } catch (ConnectionException $e) {
                $error = PixelforgeException::unreachable($e);
                $this->waitOrFail($error, $attempt);

                continue;
            }

            if ($response->successful()) {
                return $response->json();
            }

            $this->waitOrFail(PixelforgeException::fromResponse($response), $attempt);
        }
    }

    /** Sleep before the next attempt, or throw if this error is final. */
    private function waitOrFail(PixelforgeException $error, int $attempt): void
    {
        if (! $error->retryable() || $attempt >= $this->maxAttempts) {
            throw $error;
        }

        // Retry-After, doubled on every further attempt, never longer than the cap.
        $seconds = ($error->retryAfter ?? 2) * (2 ** ($attempt - 1));

        Sleep::sleep(min($seconds, $this->maxWaitSeconds));
    }

    /** @param  array<string, string>  $headers */
    private function request(array $headers, bool $authenticated): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->withHeaders($headers);

        return $authenticated ? $request->withToken((string) $this->apiKey) : $request;
    }
}
