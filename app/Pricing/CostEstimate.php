<?php

namespace App\Pricing;

/**
 * What a generation will probably cost the user, before it runs.
 *
 * The real price is only known when the generation completes, because the
 * text and reasoning token counts vary. `max` is a guaranteed ceiling: use it
 * to check affordability. `expected` is what we show as the headline price.
 * Each scenario carries both the provider cost and the user charge
 * (provider cost plus markup), in nanodollars.
 */
final readonly class CostEstimate
{
    public function __construct(
        public Settlement $min,
        public Settlement $expected,
        public Settlement $max,
    ) {}
}
