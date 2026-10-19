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
            self::Reversal => 'RV',
        };
    }

    /** Can a person reverse this kind of entry from the transactions screen? */
    public function isUserReversible(): bool
    {
        return match ($this) {
            self::Transfer, self::CapitalIn, self::CapitalOut, self::Payment, self::Receipt, self::Expense => true,
            // Opening balances change by editing the account; reversals are re-posted, not reversed.
            self::OpeningBalance, self::Reversal => false,
        };
    }

    /** Payment and receipt vouchers: one cash/bank account (line 1) and one line per party or account. */
    public function isVoucher(): bool
    {
        return $this === self::Payment || $this === self::Receipt;
    }
}
