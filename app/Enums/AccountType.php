<?php

namespace App\Enums;

/**
 * The five classes of the chart of accounts. Assets and expenses grow with debits; liabilities,
 * equity and income grow with credits.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function isDebitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Asset'),
            self::Liability => __('Liability'),
            self::Equity => __('Equity'),
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }
}
