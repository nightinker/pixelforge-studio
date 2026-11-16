<?php

namespace App\Pricing;

/** Price of one token of each kind, in nanodollars. */
final readonly class Rates
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * Build from the `rates` object of /v1/pricing or of a generation
     * (USD per 1M tokens, as decimal strings).
     *
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public static function fromApi(array $rates): self
    {
        return new self(
            inputText: Nanodollars::perTokenFromRate($rates['input_text_tokens']),
            inputImage: Nanodollars::perTokenFromRate($rates['input_image_tokens']),
            output: Nanodollars::perTokenFromRate($rates['output_tokens']),
            reasoning: Nanodollars::perTokenFromRate($rates['reasoning_tokens']),
        );
    }
}
