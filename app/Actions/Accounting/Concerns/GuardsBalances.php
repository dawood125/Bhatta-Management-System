<?php

namespace App\Actions\Accounting\Concerns;

use App\Models\TreasuryAccount;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cash, bank, wallet and person accounts may not go below zero unless "allow negative"
 * (e.g. a bank overdraft) is switched on for that account.
 */
trait GuardsBalances
{
    /**
     * Lock the given accounts (in id order, to avoid deadlocks) for the rest of the transaction.
     *
     * @param  list<int>  $ids
     * @return Collection<int, TreasuryAccount>
     */
    protected function lockTreasuryAccounts(array $ids): Collection
    {
        sort($ids);

        return TreasuryAccount::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->with('ledgerAccount')
            ->get()
            ->keyBy('id');
    }

    /**
     * Call after posting, inside the same transaction: if a balance went negative the whole
     * transaction (including the posted entry) is rolled back.
     */
    protected function assertNotNegative(TreasuryAccount $account, string $field): void
    {
        if ($account->allow_negative) {
            return;
        }

        $balance = $account->balance();

        if ($balance->isNegative()) {
            throw ValidationException::withMessages([
                $field => __('Not enough money in :account. This would make its balance :balance.', [
                    'account' => $account->displayName(),
                    'balance' => $balance->formatWithCurrency(),
                ]),
            ]);
        }
    }
}
