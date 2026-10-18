<?php

namespace App\Enums;

/**
 * What a product is. Later modules rely on this: only fired grades come out of Nakaasi,
 * raw bricks come from Pathai, and by-products are recorded separately.
 */
enum ProductKind: string
{
    case FiredGrade = 'fired_grade';
    case RawBrick = 'raw_brick';
    case ByProduct = 'by_product';

    public function label(): string
    {
        return match ($this) {
            self::FiredGrade => __('Fired brick grade'),
            self::RawBrick => __('Raw (unfired) brick'),
            self::ByProduct => __('By-product'),
        };
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
