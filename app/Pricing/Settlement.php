<?php

namespace App\Pricing;

/** The final money for one succeeded generation, in nanodollars. */
final readonly class Settlement
{
    public function __construct(
        public int $providerCost,
        public int $charge,
    ) {}

    public function profit(): int
    {
        return $this->charge - $this->providerCost;
    }
}
