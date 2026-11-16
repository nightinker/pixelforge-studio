<?php

namespace App\Pricing;

final class CostEstimator
{
    public function __construct(
        private readonly PricingTable $table,
        private readonly CostCalculator $calculator,
    ) {}

    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $table = $this->table;

        // Unicode characters, not bytes.
        $estimatedText = (int) ceil(mb_strlen($prompt) / $table->charactersPerTextToken);

        // The real text count is within +/- variance of the estimate. Round
        // the low bound down and the high bound up so the range always contains it.
        $variance = $table->textVariancePercent;
        $textMin = intdiv($estimatedText * (100 - $variance), 100);
        $textMax = intdiv($estimatedText * (100 + $variance) + 99, 100);

        ['min' => $reasoningMin, 'max' => $reasoningMax] = $table->reasoningRangeFor($quality);

        $imageTokens = $referenceImages * $table->tokensPerReferenceImage;
        $outputTokens = $table->outputTokensFor($quality, $size);

        $at = fn (int $text, int $reasoning): Settlement => $this->calculator->settle(
            new TokenUsage($text, $imageTokens, $outputTokens, $reasoning),
            $table->rates,
        );

        return new CostEstimate(
            min: $at($textMin, $reasoningMin),
            expected: $at($estimatedText, intdiv($reasoningMin + $reasoningMax, 2)),
            max: $at($textMax, $reasoningMax),
        );
    }
}
