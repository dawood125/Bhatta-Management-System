<?php

namespace App\Enums;

/**
 * Where the kiln keeps money. A credit card is money owed (a liability); the rest are assets.
 */
enum TreasuryKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Wallet = 'wallet';
    case Person = 'person';
    case CreditCard = 'credit_card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash in hand'),
            self::Bank => __('Bank account'),
            self::Wallet => __('Mobile wallet'),
            self::Person => __('Cash with a person'),
            self::CreditCard => __('Credit card'),
        };
    }

    public function ledgerType(): AccountType
    {
        return $this === self::CreditCard ? AccountType::Liability : AccountType::Asset;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'banknote',
            self::Bank => 'landmark',
            self::Wallet => 'smartphone',
            self::Person => 'hand-coins',
            self::CreditCard => 'credit-card',
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
