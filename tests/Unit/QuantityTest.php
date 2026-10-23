<?php

use App\Support\Quantity;

it('parses typed quantities exactly, in thousandths', function (string $typed, int $decimals, int $milli) {
    expect(Quantity::fromInput($typed, $decimals)->milli)->toBe($milli);
})->with([
    'whole bricks' => ['12,000', 0, 12_000_000],
    'tons' => ['31.68', 3, 31_680],
    'three decimals' => ['0.005', 3, 5],
    'lakh grouping' => ['1,50,000', 0, 150_000_000],
    'spaces' => [' 25 ', 2, 25_000],
]);

it('refuses more decimals than the unit allows, and text', function (string $typed, int $decimals) {
    Quantity::fromInput($typed, $decimals);
})->throws(InvalidArgumentException::class)->with([
    'half a bag' => ['1.5', 0],
    'four decimals' => ['1.2345', 3],
    'text' => ['ten', 2],
    'empty' => ['', 2],
    'too large' => ['1000000000001', 0],
]);

it('formats with the unit\'s decimals but never hides a digit', function () {
    expect(Quantity::fromInput('1250.5', 2)->format(2))->toBe('1,250.50')
        ->and(Quantity::fromInput('12000', 0)->format(0))->toBe('12,000')
        ->and(Quantity::fromMilli(1_005)->format(0))->toBe('1.005')
        ->and(Quantity::fromMilli(-2_500)->format(1))->toBe('-2.5')
        ->and(Quantity::fromInput('31.68', 3)->toDecimal())->toBe('31.680')
        ->and(Quantity::fromInput('31.68', 3)->toInput(2))->toBe('31.68');
});

it('adds and compares exactly', function () {
    $a = Quantity::fromInput('0.1', 3);
    $b = Quantity::fromInput('0.2', 3);

    expect($a->add($b)->equals(Quantity::fromInput('0.3', 3)))->toBeTrue()
        ->and($a->subtract($b)->isNegative())->toBeTrue()
        ->and($b->greaterThan($a))->toBeTrue()
        ->and(Quantity::zero()->isZero())->toBeTrue();
});
