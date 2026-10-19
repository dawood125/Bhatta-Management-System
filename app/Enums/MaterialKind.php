<?php

namespace App\Enums;

/**
 * What a material is used for. Firing entries (M6) offer fuels; brick making offers raw materials.
 */
enum MaterialKind: string
{
    case RawMaterial = 'raw_material';
    case Fuel = 'fuel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => __('Raw material'),
            self::Fuel => __('Fuel'),
            self::Other => __('Other'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::RawMaterial => 'warning',
            self::Fuel => 'brand',
            self::Other => 'neutral',
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
