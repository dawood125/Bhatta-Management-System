<?php

namespace App\Enums;

/**
 * What created a journal entry. Each module adds its own types (sale, expense, payroll, ...).
 */
enum JournalType: string
{
    case OpeningBalance = 'opening_balance';
    case Transfer = 'transfer';
    case CapitalIn = 'capital_in';
    case CapitalOut = 'capital_out';
    case Payment = 'payment';
    case Receipt = 'receipt';
    case Expense = 'expense';
    case MaterialPurchase = 'material_purchase';
    case MaterialUsage = 'material_usage';
    case MaterialAdjustment = 'material_adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => __('Opening balance'),
            self::Transfer => __('Transfer'),
            self::CapitalIn => __('Owner capital in'),
            self::CapitalOut => __('Owner withdrawal'),
            self::Payment => __('Payment'),
            self::Receipt => __('Receipt'),
            self::Expense => __('Expense'),
            self::MaterialPurchase => __('Material purchase'),
            self::MaterialUsage => __('Material used'),
            self::MaterialAdjustment => __('Material stock adjustment'),
            self::Reversal => __('Reversal'),
        };
    }

    /** Number prefix, e.g. TR-2026-0001. */
    public function prefix(): string
    {
        return match ($this) {
            self::OpeningBalance => 'OB',
            self::Transfer => 'TR',
            self::CapitalIn, self::CapitalOut => 'CP',
            self::Payment => 'PV',
            self::Receipt => 'RCV',
            self::Expense => 'EX',
            self::MaterialPurchase => 'PU',
            self::MaterialUsage => 'MU',
            self::MaterialAdjustment => 'SA',
            self::Reversal => 'RV',
        };
    }

    /** Can a person reverse this kind of entry from the transactions screen? */
    public function isUserReversible(): bool
    {
        return match ($this) {
            self::Transfer, self::CapitalIn, self::CapitalOut, self::Payment, self::Receipt, self::Expense => true,
            // Opening balances change by editing the account; reversals are re-posted, not reversed.
            // Material entries also move stock, so they are reversed from the material screens.
            self::OpeningBalance, self::Reversal, self::MaterialPurchase, self::MaterialUsage, self::MaterialAdjustment => false,
        };
    }

    /** Entries that also move material stock (they have stock movements next to their lines). */
    public function movesMaterialStock(): bool
    {
        return $this === self::MaterialPurchase || $this === self::MaterialUsage || $this === self::MaterialAdjustment;
    }

    /** Payment and receipt vouchers: one cash/bank account (line 1) and one line per party or account. */
    public function isVoucher(): bool
    {
        return $this === self::Payment || $this === self::Receipt;
    }
}
