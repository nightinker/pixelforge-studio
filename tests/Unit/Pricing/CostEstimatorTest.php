<?php

use App\Pricing\CostCalculator;
use App\Pricing\CostEstimator;
use App\Pricing\PricingTable;

function estimator(int $markup = 25): CostEstimator
{
    $response = json_decode(file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true);

    return new CostEstimator(PricingTable::fromApi($response), new CostCalculator($markup));
}

it('parses the pricing response', function () {
    $table = PricingTable::fromApi(json_decode(file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true));

    expect($table->textVariancePercent)->toBe(15)
        ->and($table->charactersPerTextToken)->toBe(4)
        ->and($table->tokensPerReferenceImage)->toBe(765)
        ->and($table->rates->reasoning)->toBe(12_500)
        ->and($table->outputTokensFor('high', '1536x1024'))->toBe(6450)
        ->and($table->reasoningRangeFor('medium'))->toBe(['min' => 200, 'max' => 2000]);
});

it('reproduces the maximum cost worked example from the API docs', function () {
    // high 1536x1024, 400-character prompt, 2 reference images, worst case.
    $estimate = estimator()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($estimate->max->providerCost)->toBe(373_875_000) // $0.373875
        ->and($estimate->max->charge)->toBe(467_343_750);   // plus 25%
});

it('computes the expected and minimum scenarios', function () {
    $estimate = estimator()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

    // expected: 100 text, midpoint reasoning 4500
    expect($estimate->expected->providerCost)->toBe(330_050_000)
        // min: floor(100 * 0.85) = 85 text, reasoning 1000
        ->and($estimate->min->providerCost)->toBe(
            85 * 5_000 + 1_530 * 10_000 + 6_450 * 40_000 + 1_000 * 12_500,
        );
});

it('always orders min <= expected <= max', function (string $prompt, string $size, string $quality, int $images) {
    $e = estimator()->estimate($prompt, $size, $quality, $images);

    expect($e->min->charge)->toBeLessThanOrEqual($e->expected->charge)
        ->and($e->expected->charge)->toBeLessThanOrEqual($e->max->charge);
})->with([
    ['a', '1024x1024', 'low', 0],
    [str_repeat('x', 4000), '1024x1536', 'high', 4],
    ['hello world', '1536x1024', 'medium', 1],
]);

it('counts unicode characters, not bytes', function () {
    // 8 emoji = 8 characters (32 bytes) => 2 text tokens, not 8.
    $estimate = estimator()->estimate(str_repeat('😀', 8), '1024x1024', 'low', 0);

    // 2 text + 280 output + midpoint reasoning 250
    expect($estimate->expected->providerCost)->toBe(2 * 5_000 + 280 * 40_000 + 250 * 12_500);
});

it('rounds the text estimate up', function () {
    // 5 characters => ceil(5/4) = 2 tokens.
    $estimate = estimator()->estimate('abcde', '1024x1024', 'low', 0);

    expect($estimate->expected->providerCost)->toBe(2 * 5_000 + 280 * 40_000 + 250 * 12_500);
});

it('bills each reference image at exactly 765 tokens', function () {
    $none = estimator()->estimate('abcd', '1024x1024', 'low', 0);
    $three = estimator()->estimate('abcd', '1024x1024', 'low', 3);

    expect($three->expected->providerCost - $none->expected->providerCost)->toBe(3 * 765 * 10_000);
});

it('rejects a size or quality the provider does not offer', function (string $size, string $quality) {
    estimator()->estimate('hi', $size, $quality, 0);
})->with([
    ['100x100', 'low'],
    ['1024x1024', 'ultra'],
])->throws(InvalidArgumentException::class);
