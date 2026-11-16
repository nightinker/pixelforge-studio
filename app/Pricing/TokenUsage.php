<?php

namespace App\Pricing;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * Build from the `usage` object of a succeeded generation.
     *
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     */
    public static function fromApi(array $usage): self
    {
        return new self(
            inputText: (int) $usage['input_text_tokens'],
            inputImage: (int) $usage['input_image_tokens'],
            output: (int) $usage['output_tokens'],
            reasoning: (int) $usage['reasoning_tokens'],
        );
    }
}
