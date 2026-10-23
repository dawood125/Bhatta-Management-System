<?php

namespace App\Enums;

/**
 * Production work that a piece-rate category is paid for. Recording that production entry with
 * a worker of such a category earns them its bricks at the rate card.
 */
enum LabourActivity: string
{
    case Pathai = 'pathai';
    case Loading = 'loading';
    case Nakaasi = 'nakaasi';

    public function label(): string
    {
        return match ($this) {
            self::Pathai => __('Pathai: bricks accepted'),
            self::Loading => __('Loading into the kiln (Kamyaar)'),
            self::Nakaasi => __('Nakaasi: bricks unloaded'),
        };
    }

    public function entryKind(): ProductionEntryKind
    {
        return match ($this) {
            self::Pathai => ProductionEntryKind::Pathai,
            self::Loading => ProductionEntryKind::Loading,
            self::Nakaasi => ProductionEntryKind::Nakaasi,
        };
    }

    public static function forEntryKind(ProductionEntryKind $kind): ?self
    {
        return match ($kind) {
            ProductionEntryKind::Pathai => self::Pathai,
            ProductionEntryKind::Loading => self::Loading,
            ProductionEntryKind::Nakaasi => self::Nakaasi,
            ProductionEntryKind::Firing, ProductionEntryKind::Adjustment => null,
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
