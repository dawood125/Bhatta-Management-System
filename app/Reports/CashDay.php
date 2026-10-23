<?php

namespace App\Reports;

use App\Accounting\LedgerQueries;
use App\Models\TreasuryAccount;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * One day of every cash/bank account: the balance before the day, money in, money out and the
 * balance at the end, so that opening + in − out = closing. "In" is what raises the account's
 * balance (for a credit card: more owed).
 */
class CashDay
{
    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly LedgerQueries $ledger,
    ) {}

    /**
     * Active accounts, plus archived ones that moved that day or still hold money.
     *
     * @return list<array{account: TreasuryAccount, opening: Money, in: Money, out: Money, closing: Money}>
     */
    public function forDate(CarbonInterface $date): array
    {
        $accounts = TreasuryAccount::query()->with('ledgerAccount')->orderBy('sort_order')->orderBy('name')->get();
        $ledgerIds = $accounts->pluck('ledger_account_id')->all();

        $opening = $this->ledger->balances($ledgerIds, $date->copy()->subDay());
        $day = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.kiln_id', $this->currentKiln->id())
            ->whereIn('journal_lines.ledger_account_id', $ledgerIds)
            ->where('journal_entries.entry_date', $date->format('Y-m-d'))
            ->groupBy('journal_lines.ledger_account_id')
            ->select('journal_lines.ledger_account_id')
            ->selectRaw('SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->get()
            ->keyBy('ledger_account_id');

        $rows = [];
        foreach ($accounts as $account) {
            $sums = $day->get($account->ledger_account_id);
            $debits = Money::fromPaisa((int) ($sums->debits ?? 0));
            $credits = Money::fromPaisa((int) ($sums->credits ?? 0));
            $debitNormal = $account->ledgerAccount->type->isDebitNormal();
            $in = $debitNormal ? $debits : $credits;
            $out = $debitNormal ? $credits : $debits;
            $start = $opening[$account->ledger_account_id] ?? Money::zero();
            $closing = $start->add($in)->subtract($out);

            if (! $account->is_active && $in->isZero() && $out->isZero() && $closing->isZero()) {
                continue;
            }

            $rows[] = ['account' => $account, 'opening' => $start, 'in' => $in, 'out' => $out, 'closing' => $closing];
        }

        return $rows;
    }
}
