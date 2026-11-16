<?php

namespace App\Services;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\CostCalculator;
use App\Pricing\CostEstimate;
use App\Pricing\CostEstimator;

class GenerationService
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly CostCalculator $calculator,
    ) {}

    /**
     * What a generation will cost, from Pixelforge's current rates.
     *
     * @throws \App\Pixelforge\PixelforgeException when the rates cannot be fetched
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): CostEstimate
    {
        return (new CostEstimator($this->client->pricingTable(), $this->calculator))
            ->estimate($prompt, $size, $quality, $referenceImages);
    }

    /**
     * Record a generation request and queue it for sending.
     *
     * The estimate is recomputed here, never taken from the browser.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>|null}  $data
     */
    public function create(User $user, array $data): Generation
    {
        $images = array_values($data['reference_images'] ?? []);

        $estimate = $this->estimate($data['prompt'], $data['size'], $data['quality'], count($images));

        $generation = $user->generations()->create([
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $images,
            'estimated_charge_nano' => $estimate->expected->charge,
            'estimated_max_charge_nano' => $estimate->max->charge,
        ]);

        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }
}
