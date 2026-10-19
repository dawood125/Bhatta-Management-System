<?php

namespace App\Enums;

/**
 * Why a material's stock changed. Every change is one movement row; stock is never overwritten.
 */
enum MaterialMovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Usage = 'usage';
    case Shortage = 'shortage';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Opening => __('Opening stock'),
            self::Purchase => __('Purchase'),
            self::Usage => __('Used'),
            self::Shortage => __('Shortage / wastage'),
            self::Reversal => __('Reversal'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Opening => 'info',
            self::Purchase => 'success',
            self::Usage => 'brand',
            self::Shortage => 'warning',
            self::Reversal => 'danger',
        };
    }
}
