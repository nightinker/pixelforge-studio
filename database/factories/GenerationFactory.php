<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Generation> */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_charge_nano' => 100_000_000,
            'estimated_max_charge_nano' => 200_000_000,
        ];
    }

    /** A succeeded generation that has not been priced yet. */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'provider_id' => 'gen_'.fake()->unique()->regexify('[0-9A-Z]{26}'),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'started_at' => now()->subSeconds(20),
            'completed_at' => now(),
        ]);
    }
}
