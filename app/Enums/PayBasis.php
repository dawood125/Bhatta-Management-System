<?php

namespace App\Enums;

/**
 * How a labour category is paid. Rates are kiln settings with effective dates, never constants.
 */
enum PayBasis: string
{
    case PerThousand = 'per_thousand';
    case PerDay = 'per_day';
    case PerMonth = 'per_month';
    case LumpSum = 'lump_sum';

    public function label(): string
    {
        return match ($this) {
            self::PerThousand => __('Per 1,000 bricks (piece rate)'),
            self::PerDay => __('Per day (daily wage)'),
            self::PerMonth => __('Per month (salary)'),
            self::LumpSum => __('Lump sum (contract)'),
        };
    }

    /** What the rate is for, e.g. "per 1,000 bricks". Null for a lump sum. */
    public function rateUnit(): ?string
    {
        return match ($this) {
            self::PerThousand => __('per 1,000 bricks'),
            self::PerDay => __('per day'),
            self::PerMonth => __('per month'),
            self::LumpSum => null,
        };
    }

    /** What a work entry counts, e.g. bricks or days. Null when only an amount is entered. */
    public function quantityLabel(): ?string
    {
        return match ($this) {
            self::PerThousand => __('Bricks'),
            self::PerDay => __('Days'),
            self::PerMonth => __('Paid days'),
            self::LumpSum => null,
        };
    }

    /** Decimal places of the quantity: whole bricks, half days. */
    public function decimals(): int
    {
        return match ($this) {
            self::PerThousand => 0,
            self::PerDay, self::PerMonth => 1,
            self::LumpSum => 0,
        };
    }

    public function hasRate(): bool
    {
        return $this !== self::LumpSum;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
