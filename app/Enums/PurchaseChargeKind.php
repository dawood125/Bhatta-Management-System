<?php

namespace App\Enums;

/**
 * Extra costs of bringing a material to the kiln. They are added to the material's cost
 * (landed cost), not booked as a separate expense.
 */
enum PurchaseChargeKind: string
{
    case Transport = 'transport';
    case Excavator = 'excavator';
    case Loading = 'loading';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Transport => __('Trolley / transport'),
            self::Excavator => __('Excavator'),
            self::Loading => __('Loading / unloading'),
            self::Other => __('Other charge'),
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
