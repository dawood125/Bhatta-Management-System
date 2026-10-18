<?php

namespace App\Enums;

/**
 * The fixed production stages where bricks are held. Kilns can rename them, but the kinds
 * themselves are fixed because stock rules depend on them (e.g. only "saleable" stock can be
 * sold; bricks that are fired but not yet unloaded can never be sold).
 *
 * Bricks that are rejected, sold or broken leave the stages through movements (module M6),
 * they are not stages themselves.
 */
enum StageKind: string
{
    case Raw = 'raw';
    case InFiring = 'in_firing';
    case FiredAwaitingUnloading = 'fired_awaiting_unloading';
    case Saleable = 'saleable';

    /** Default English name (kilns can rename). */
    public function defaultName(): string
    {
        return match ($this) {
            self::Raw => 'Raw bricks (Pathai)',
            self::InFiring => 'In the kiln (Julai)',
            self::FiredAwaitingUnloading => 'Fired, awaiting Nakaasi',
            self::Saleable => 'Ready for sale',
        };
    }

    /** Default Urdu name (kilns can rename). */
    public function defaultNameUrdu(): string
    {
        return match ($this) {
            self::Raw => 'کچی اینٹ (پتھائی)',
            self::InFiring => 'بھٹے میں (جلائی)',
            self::FiredAwaitingUnloading => 'پکی، نکاسی باقی',
            self::Saleable => 'فروخت کے لیے تیار',
        };
    }

    /** What the stage means, shown under the name so renamed stages stay understandable. */
    public function meaning(): string
    {
        return match ($this) {
            self::Raw => __('Accepted raw bricks after Pathai, drying and waiting to go into the kiln.'),
            self::InFiring => __('Bricks placed in the kiln and being fired.'),
            self::FiredAwaitingUnloading => __('Firing is complete but the bricks are not unloaded yet. They cannot be sold.'),
            self::Saleable => __('Unloaded and sorted by grade. Only these bricks can be sold.'),
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::Raw => 1,
            self::InFiring => 2,
            self::FiredAwaitingUnloading => 3,
            self::Saleable => 4,
        };
    }
}
