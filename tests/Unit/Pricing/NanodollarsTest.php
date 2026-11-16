<?php

use App\Pricing\Nanodollars;

it('converts a published rate to nanodollars per token', function (string $rate, int $expected) {
    expect(Nanodollars::perTokenFromRate($rate))->toBe($expected);
})->with([
    ['5.00', 5000],
    ['10.00', 10000],
    ['40.00', 40000],
    ['12.50', 12500],
    ['3', 3000],
    ['5.1', 5100],
    ['0.001', 1],
    ['1.0000', 1000], // trailing zeros are not extra precision
]);

it('rejects rates it cannot represent exactly or that are malformed', function (string $rate) {
    Nanodollars::perTokenFromRate($rate);
})->with(['1.0001', 'abc', '-1', '.5', '', '1e3'])->throws(InvalidArgumentException::class);

it('formats nanodollars with exactly nine decimals', function (int $nano, string $expected) {
    expect(Nanodollars::toDecimal($nano))->toBe($expected);
})->with([
    [0, '0.000000000'],
    [1, '0.000000001'],
    [46_125_000, '0.046125000'],
    [305_510_000, '0.305510000'],
    [1_000_000_000, '1.000000000'],
    [12_345_678_901, '12.345678901'],
]);

it('parses decimal strings back to the same nanodollars', function (int $nano) {
    expect(Nanodollars::fromDecimal(Nanodollars::toDecimal($nano)))->toBe($nano);
})->with([0, 1, 46_125_000, 169_080_000, 999_999_999, 1_000_000_000, 12_345_678_901]);

it('parses short decimals and rejects bad ones', function () {
    expect(Nanodollars::fromDecimal('1.5'))->toBe(1_500_000_000)
        ->and(Nanodollars::fromDecimal('2'))->toBe(2_000_000_000);

    expect(fn () => Nanodollars::fromDecimal('0.1234567891'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Nanodollars::fromDecimal('-1'))->toThrow(InvalidArgumentException::class);
});

it('formats for display without rounding', function (int $nano, string $expected) {
    expect(Nanodollars::format($nano))->toBe($expected);
})->with([
    [0, '$0.00'],
    [1_500_000_000, '$1.50'],
    [46_125_000, '$0.046125'],
    [1, '$0.000000001'],
    [467_343_750, '$0.46734375'],
]);
