<?php

namespace App\Enums;

/**
 * A salaried worker's day. Days not marked count as present: only absences and half days are
 * usually marked.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Half = 'half';
    case Absent = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::Present => __('Present'),
            self::Half => __('Half day'),
            self::Absent => __('Absent'),
        };
    }

    /** Unpaid half days (so salary maths stays in whole numbers): absent 2, half day 1. */
    public function unpaidHalves(): int
    {
        return match ($this) {
            self::Present => 0,
            self::Half => 1,
            self::Absent => 2,
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
