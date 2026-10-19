<?php

use App\Support\Money;

describe('parsing rupee input', function () {
    it('parses amounts typed by people', function (string|int $input, int $paisa) {
        expect(Money::fromRupees($input)->paisa)->toBe($paisa);
    })->with([
        ['0', 0],
        ['1250', 125000],
        ['1,250', 125000],
        ['1,250.5', 125050],
        ['1250.05', 125005],
        ['-300.75', -30075],
        [' 12 ', 1200],
        ['13,800.00', 1380000],
        'lakh grouping, as written in Pakistan' => ['1,50,000', 15000000],
        [5, 500],
    ]);

    it('rejects anything that is not a plain rupee amount', function (string $input) {
        Money::fromRupees($input);
    })->with([
        'more than 2 decimals' => '1.234',
        'letters' => 'abc',
        'empty' => '',
        'scientific notation' => '1e3',
        'double minus' => '--1',
        'trailing dot' => '12.',
        'plus sign' => '+5',
    ])->throws(InvalidArgumentException::class);

    it('rejects amounts beyond the supported range', function () {
        Money::fromRupees('10000000000001');
    })->throws(InvalidArgumentException::class);
});

describe('arithmetic', function () {
    it('adds, subtracts, negates and sums exactly', function () {
        $a = Money::fromRupees('0.10');
        $b = Money::fromRupees('0.20');

        // The classic float trap 0.1 + 0.2 != 0.3 cannot happen with paisa.
        expect($a->add($b)->equals(Money::fromRupees('0.30')))->toBeTrue()
            ->and($b->subtract($a)->paisa)->toBe(10)
            ->and($a->negate()->paisa)->toBe(-10)
            ->and(Money::fromRupees('-5')->abs()->paisa)->toBe(500)
            ->and(Money::sum([$a, $b, Money::fromRupees('1')])->paisa)->toBe(130)
            ->and(Money::sum([])->isZero())->toBeTrue();
    });

    it('compares amounts', function () {
        $small = Money::fromRupees('1');
        $big = Money::fromRupees('2');

        expect($big->greaterThan($small))->toBeTrue()
            ->and($small->lessThan($big))->toBeTrue()
            ->and($small->equals(Money::fromPaisa(100)))->toBeTrue()
            ->and(Money::zero()->isZero())->toBeTrue()
            ->and($big->isPositive())->toBeTrue()
            ->and($big->negate()->isNegative())->toBeTrue();
    });
});

describe('rate × quantity (the owner\'s example rates)', function () {
    it('prices quantities at a rate per basis quantity', function (string $rate, int|string $quantity, int $per, string $expected) {
        expect(Money::fromRupees($rate)->timesQuantity($quantity, per: $per)->toDecimal())->toBe($expected);
    })->with([
        'Pathai 11,500 bricks @ 1,200 / 1,000' => ['1200', 11500, 1000, '13800.00'],
        'Kamyaar 11,000 bricks @ 410 / 1,000' => ['410', 11000, 1000, '4510.00'],
        'Nakaasi 11,000 bricks @ 325 / 1,000' => ['325', 11000, 1000, '3575.00'],
        'Sale 5,000 Awal @ 15,000 / 1,000' => ['15000', 5000, 1000, '75000.00'],
        '1 brick @ 1,250 / 1,000' => ['1250', 1, 1000, '1.25'],
        'decimal tons: 31.68 t @ 17,050 / t' => ['17050', '31.68', 1, '540144.00'],
        'zero quantity' => ['1200', 0, 1000, '0.00'],
    ]);

    it('rounds half a paisa up, away from zero, exactly once', function () {
        $onePaisa = Money::fromPaisa(1);

        expect($onePaisa->timesQuantity('0.5')->paisa)->toBe(1)
            ->and($onePaisa->timesQuantity('0.49')->paisa)->toBe(0)
            ->and($onePaisa->negate()->timesQuantity('0.5')->paisa)->toBe(-1)
            // 333 bricks @ 1 rupee per 1,000 = 33.3 paisa -> 33 paisa
            ->and(Money::fromRupees('1')->timesQuantity(333, per: 1000)->paisa)->toBe(33)
            // 335 bricks @ 1 rupee per 1,000 = 33.5 paisa -> 34 paisa
            ->and(Money::fromRupees('1')->timesQuantity(335, per: 1000)->paisa)->toBe(34);
    });

    it('refuses an invalid basis or quantity', function (int|string $quantity, int $per) {
        Money::fromRupees('100')->timesQuantity($quantity, per: $per);
    })->with([
        'zero basis' => [10, 0],
        'negative basis' => [10, -1000],
        'non-numeric quantity' => ['ten', 1],
    ])->throws(InvalidArgumentException::class);

    it('refuses results beyond the supported range', function () {
        Money::fromRupees('9000000000')->timesQuantity(1000000);
    })->throws(InvalidArgumentException::class);
});

describe('formatting', function () {
    it('formats for display and for inputs', function (int $paisa, string $formatted, string $decimal) {
        $money = Money::fromPaisa($paisa);

        expect($money->format())->toBe($formatted)
            ->and($money->toDecimal())->toBe($decimal)
            ->and((string) $money)->toBe($formatted)
            ->and(json_encode($money))->toBe('"'.$decimal.'"');
    })->with([
        [0, '0.00', '0.00'],
        [5, '0.05', '0.05'],
        [-5, '-0.05', '-0.05'],
        [123456750, '1,234,567.50', '1234567.50'],
        [-1380000, '-13,800.00', '-13800.00'],
    ]);
});
