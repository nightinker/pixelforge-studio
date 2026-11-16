<?php

use App\Pricing\CostCalculator;
use App\Pricing\Nanodollars;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;

function docRates(): Rates
{
    return Rates::fromApi([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);
}

it('matches the succeeded generation example in the API docs', function () {
    $usage = new TokenUsage(inputText: 12, inputImage: 765, output: 6450, reasoning: 3184);

    // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 305_510 micro-dollars
    expect((new CostCalculator(25))->providerCost($usage, docRates()))->toBe(305_510_000);
});

it('matches the GET /v1/usage example in the API docs', function () {
    // Day total from the docs: 41 text, 0 image, 3300 output, 2950 reasoning => 0.169080000
    $usage = new TokenUsage(41, 0, 3300, 2950);

    $cost = (new CostCalculator(25))->providerCost($usage, docRates());

    expect(Nanodollars::toDecimal($cost))->toBe('0.169080000');
});

it('costs nothing for zero usage', function () {
    expect((new CostCalculator(25))->providerCost(new TokenUsage(0, 0, 0, 0), docRates()))->toBe(0);
});

it('adds the markup exactly', function () {
    $settlement = (new CostCalculator(25))->settle(new TokenUsage(12, 765, 6450, 3184), docRates());

    expect($settlement->providerCost)->toBe(305_510_000)
        ->and($settlement->charge)->toBe(381_887_500)
        ->and($settlement->profit())->toBe(76_377_500);
});

it('rounds a fractional nanodollar half up, deterministically', function (int $provider, int $expected) {
    expect((new CostCalculator(25))->charge($provider))->toBe($expected);
})->with([
    'x.25 rounds down' => [1, 1],   // 1.25
    'x.50 rounds up' => [2, 3],     // 2.5
    'x.75 rounds up' => [3, 4],     // 3.75
    'exact stays exact' => [4, 5],  // 5.0
    'x.50 rounds up again' => [6, 8], // 7.5
]);

it('supports a different markup and none at all', function () {
    expect((new CostCalculator(0))->charge(123_456_789))->toBe(123_456_789)
        ->and((new CostCalculator(100))->charge(1_000))->toBe(2_000);
});

it('rejects a negative markup', function () {
    new CostCalculator(-1);
})->throws(InvalidArgumentException::class);

it('agrees with an arbitrary-precision oracle for random usage', function () {
    mt_srand(20261116);
    $calculator = new CostCalculator(25);

    for ($i = 0; $i < 500; $i++) {
        $rateStrings = [
            'input_text_tokens' => sprintf('%d.%02d', mt_rand(0, 20), mt_rand(0, 99)),
            'input_image_tokens' => sprintf('%d.%02d', mt_rand(0, 40), mt_rand(0, 99)),
            'output_tokens' => sprintf('%d.%02d', mt_rand(0, 200), mt_rand(0, 99)),
            'reasoning_tokens' => sprintf('%d.%02d', mt_rand(0, 60), mt_rand(0, 99)),
        ];
        $tokens = [
            'input_text_tokens' => mt_rand(0, 1200),
            'input_image_tokens' => 765 * mt_rand(0, 4),
            'output_tokens' => mt_rand(0, 6450),
            'reasoning_tokens' => mt_rand(0, 8000),
        ];

        // Oracle: dollars = sum(tokens * rate) / 1,000,000, all in bcmath.
        $sum = '0';
        foreach ($tokens as $key => $count) {
            $sum = bcadd($sum, bcmul((string) $count, $rateStrings[$key], 2), 2);
        }
        $oracleNano = bcmul(bcdiv($sum, '1000000', 12), '1000000000', 0);
        // Markup, rounded half up to a whole nanodollar.
        $oracleCharge = bcadd(bcadd(bcmul($oracleNano, '1.25', 4), '0.5', 4), '0', 0);

        $cost = $calculator->providerCost(TokenUsage::fromApi($tokens), Rates::fromApi($rateStrings));
        $charge = $calculator->charge($cost);

        expect($cost)->toBeInt()->and((string) $cost)->toBe($oracleNano)
            ->and((string) $charge)->toBe($oracleCharge);
    }
});

it('needs no rounding at all with the published rates', function () {
    mt_srand(77);
    $calculator = new CostCalculator(25);

    for ($i = 0; $i < 500; $i++) {
        $usage = new TokenUsage(mt_rand(0, 1200), 765 * mt_rand(0, 4), mt_rand(0, 6450), mt_rand(0, 8000));
        $cost = $calculator->providerCost($usage, docRates());

        // Exactly 125% of the provider cost, with no remainder to round.
        expect(($cost * 125) % 100)->toBe(0)
            ->and($calculator->charge($cost) * 100)->toBe($cost * 125);
    }
});
