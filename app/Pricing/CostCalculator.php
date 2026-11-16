<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Turns token usage into money, exactly, using integer arithmetic only.
 *
 * Provider cost is what Pixelforge bills us. The charge is what the user pays:
 * provider cost plus the markup.
 */
final class CostCalculator
{
    public function __construct(private readonly int $markupPercent)
    {
        if ($markupPercent < 0) {
            throw new InvalidArgumentException('Markup cannot be negative.');
        }
    }

    /** What Pixelforge bills us for this usage, in nanodollars. */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        return $usage->inputText * $rates->inputText
            + $usage->inputImage * $rates->inputImage
            + $usage->output * $rates->output
            + $usage->reasoning * $rates->reasoning;
    }

    /**
     * What the user pays for a given provider cost, in nanodollars.
     *
     * With the published rates ($5, $10, $40 and $12.50 per 1M tokens) every
     * per-token price is divisible by 4 nanodollars, so a 25% markup is a whole
     * number of nanodollars and this is exact. If a rate ever changes so that it
     * is not, the remainder is rounded half up to the nearest nanodollar, so the
     * result is always one well-defined integer that is stored once per generation.
     */
    public function charge(int $providerCost): int
    {
        return intdiv($providerCost * (100 + $this->markupPercent) + 50, 100);
    }

    /** Provider cost and user charge for finished usage. */
    public function settle(TokenUsage $usage, Rates $rates): Settlement
    {
        $providerCost = $this->providerCost($usage, $rates);

        return new Settlement($providerCost, $this->charge($providerCost));
    }
}
