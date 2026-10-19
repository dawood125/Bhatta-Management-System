<?php

use App\Actions\Expenses\RecordExpense;
use App\Actions\Vouchers\RecordVoucher;
use App\Enums\JournalType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Record a payment or receipt voucher through the real action.
 *
 * @param  list<array{0: Party|TreasuryAccount, 1: string, 2?: string}>  $lines  [party or account, amount, remarks]
 */
function recordVoucher(User $actor, JournalType $type, TreasuryAccount $account, array $lines, array $extra = [], ?string $key = null): JournalEntry
{
    return app(RecordVoucher::class)->handle($actor, $type, array_merge([
        'account_id' => (string) $account->id,
        'entry_date' => now()->format('Y-m-d'),
        'memo' => '',
        'lines' => array_map(fn (array $line) => voucherLine(...$line), $lines),
    ], $extra), $key ?? (string) Str::uuid());
}

/** One voucher line as the form sends it. */
function voucherLine(Party|TreasuryAccount $target, string $amount, string $memo = ''): array
{
    return [
        'type' => $target instanceof Party ? RecordVoucher::LINE_PARTY : RecordVoucher::LINE_ACCOUNT,
        'party_id' => $target instanceof Party ? (string) $target->id : '',
        'account_id' => $target instanceof TreasuryAccount ? (string) $target->id : '',
        'amount' => $amount,
        'memo' => $memo,
    ];
}

/** Record an expense through the real action. */
function recordExpense(User $actor, TreasuryAccount $account, ExpenseCategory $category, string $amount, array $extra = [], ?string $key = null): Expense
{
    return app(RecordExpense::class)->handle($actor, array_merge([
        'account_id' => (string) $account->id,
        'entry_date' => now()->format('Y-m-d'),
        'expense_category_id' => (string) $category->id,
        'expense_sub_category_id' => '',
        'amount' => $amount,
        'description' => '',
    ], $extra), $key ?? (string) Str::uuid());
}
