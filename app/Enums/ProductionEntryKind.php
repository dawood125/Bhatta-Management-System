<?php

namespace App\Enums;

/**
 * The daily production entries. Each moves bricks between the fixed stages.
 */
enum ProductionEntryKind: string
{
    case Pathai = 'pathai';
    case Loading = 'loading';
    case Firing = 'firing';
    case Nakaasi = 'nakaasi';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Pathai => __('Pathai (bricks made)'),
            self::Loading => __('Loaded into the kiln'),
            self::Firing => __('Firing complete'),
            self::Nakaasi => __('Nakaasi (unloaded)'),
            self::Adjustment => __('Stock adjustment'),
        };
    }

    /** Number prefix, e.g. PT-2026-0001. */
    public function prefix(): string
    {
        return match ($this) {
            self::Pathai => 'PT',
            self::Loading => 'LD',
            self::Firing => 'FR',
            self::Nakaasi => 'NK',
            self::Adjustment => 'BA',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pathai => 'warning',
            self::Loading => 'brand',
            self::Firing => 'danger',
            self::Nakaasi => 'success',
            self::Adjustment => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pathai => 'hand',
            self::Loading => 'arrow-down-to-line', // not left/right, so it reads the same in Urdu
            self::Firing => 'flame',
            self::Nakaasi => 'layers',
            self::Adjustment => 'clipboard-pen',
        };
    }
}
