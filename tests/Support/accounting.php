<?php

use App\Actions\Accounting\RecordCapital;
use App\Actions\Accounting\RecordTransfer;
use App\Actions\Accounting\SaveTreasuryAccount;
use App\Enums\TreasuryKind;
use App\Models\JournalEntry;
use App\Models\TreasuryAccount;
use App\Models\User;
use Illuminate\Support\Str;

/** Create a cash/bank account through the real action (opening balance posted to the ledger). */
function makeTreasuryAccount(User $actor, string $name, string $opening = '0', TreasuryKind $kind = TreasuryKind::Cash, array $extra = []): TreasuryAccount
{
    return app(SaveTreasuryAccount::class)->handle($actor, null, array_merge([
        'kind' => $kind->value,
        'name' => $name,
        'name_ur' => '',
        'account_number' => '',
        'opening_balance' => $opening,
        'opening_date' => now()->subDays(30)->format('Y-m-d'),
        'allow_negative' => false,
    ], $extra), (string) Str::uuid());
}

function transferMoney(User $actor, TreasuryAccount $from, TreasuryAccount $to, string $amount, ?string $key = null, ?string $date = null): JournalEntry
{
    return app(RecordTransfer::class)->handle($actor, [
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => $amount,
        'entry_date' => $date ?? now()->format('Y-m-d'),
        'memo' => '',
    ], $key ?? (string) Str::uuid());
}

function recordCapital(User $actor, string $direction, TreasuryAccount $account, string $amount): JournalEntry
{
    return app(RecordCapital::class)->handle($actor, [
        'direction' => $direction,
        'account_id' => $account->id,
        'amount' => $amount,
        'entry_date' => now()->format('Y-m-d'),
        'memo' => '',
    ], (string) Str::uuid());
}

function balanceOf(TreasuryAccount $account): string
{
    return $account->fresh()->load('ledgerAccount')->balance()->toDecimal();
}
