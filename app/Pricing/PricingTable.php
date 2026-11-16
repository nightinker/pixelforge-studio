<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Everything needed to estimate a generation, as published by GET /v1/pricing.
 * Read it from the API rather than hardcoding: rates and token tables can change.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textVariancePercent,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param  array<string, mixed>  $response  the decoded GET /v1/pricing body */
    public static function fromApi(array $response): self
    {
        $tokens = $response['tokens'];

        return new self(
            rates: Rates::fromApi($response['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // The API publishes the variance as a fraction (0.15); keep it as whole percent.
            textVariancePercent: (int) round($tokens['text_token_variance'] * 100),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    public function outputTokensFor(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("Unknown quality/size: {$quality} {$size}");
    }

    /** @return array{min: int, max: int} */
    public function reasoningRangeFor(string $quality): array
    {
        return $this->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("Unknown quality: {$quality}");
    }
}
