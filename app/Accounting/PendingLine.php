<?php

namespace App\Accounting;

use App\Models\LedgerAccount;
use App\Models\Party;
use App\Support\Money;

/**
 * One line of a journal entry that is about to be posted.
 */
final class PendingLine
{
    private function __construct(
        public readonly LedgerAccount $account,
        public readonly Money $debit,
        public readonly Money $credit,
        public readonly ?string $memo,
        public readonly ?Party $party = null,
    ) {}

    public static function debit(LedgerAccount $account, Money $amount, ?string $memo = null): self
    {
        return new self($account, $amount, Money::zero(), $memo);
    }

    public static function credit(LedgerAccount $account, Money $amount, ?string $memo = null): self
    {
        return new self($account, Money::zero(), $amount, $memo);
    }

    /** Tag the line with a customer/supplier (required on receivable and payable accounts). */
    public function forParty(?Party $party): self
    {
        return new self($this->account, $this->debit, $this->credit, $this->memo, $party);
    }
}
