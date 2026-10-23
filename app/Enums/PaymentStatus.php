<?php

namespace App\Enums;

/**
 * How much of a sale the customer has paid, worked out from their account (App\Sales\CustomerPayments).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => __('Unpaid'),
            self::PartlyPaid => __('Partly paid'),
            self::Paid => __('Paid'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartlyPaid => 'warning',
            self::Paid => 'success',
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
