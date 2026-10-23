<?php

namespace App\Accounting;

use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Support\JournalPresenter;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views of the journal: balances, account statements and the trial balance.
 * Everything is calculated from journal lines; nothing is stored as a running total.
 *
 * Reversed entries and their reversals are both included: together they net to zero, which
 * keeps every report consistent with the full history.
 */
class LedgerQueries
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    /**
     * Balance in the account's natural direction: for assets and expenses debits minus credits,
     * for liabilities, equity and income credits minus debits.
     */
    public function balance(LedgerAccount $account, ?CarbonInterface $asOf = null): Money
    {
        return $this->balances([$account->id], $asOf)[$account->id] ?? Money::zero();
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    public function balances(array $accountIds, ?CarbonInterface $asOf = null): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = $this->sumsQuery($asOf)
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            ->get();

        $accounts = LedgerAccount::query()->whereKey($accountIds)->get()->keyBy('id');

        $result = [];
        foreach ($accountIds as $id) {
            $result[$id] = Money::zero();
        }

        foreach ($rows as $row) {
            $net = (int) $row->debits - (int) $row->credits;
            $type = $accounts[(int) $row->ledger_account_id]->type;
            $result[(int) $row->ledger_account_id] = Money::fromPaisa($type->isDebitNormal() ? $net : -$net);
        }

        return $result;
    }

    /**
     * Every account with activity up to the date, with its net balance on the debit or the
     * credit side. Total debits always equal total credits.
     *
     * @return array{rows: Collection<int, array{account: LedgerAccount, debit: Money, credit: Money}>, total_debit: Money, total_credit: Money}
     */
    public function trialBalance(CarbonInterface $asOf): array
    {
        $sums = $this->sumsQuery($asOf)->get()->keyBy('ledger_account_id');
        $accounts = LedgerAccount::query()->whereKey($sums->keys()->all())->orderBy('code')->get();

        $rows = collect();
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($accounts as $account) {
            $net = (int) $sums[$account->id]->debits - (int) $sums[$account->id]->credits;
            if ($net === 0) {
                continue;
            }

            $debit = Money::fromPaisa(max($net, 0));
            $credit = Money::fromPaisa(max(-$net, 0));
            $rows->push(['account' => $account, 'debit' => $debit, 'credit' => $credit]);
            $totalDebit = $totalDebit->add($debit);
            $totalCredit = $totalCredit->add($credit);
        }

        return ['rows' => $rows, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    /**
     * Statement of one account for a period: balance before the period, each line with a
     * running balance, and the closing balance. "In" increases the natural balance.
     *
     * @return array{opening: Money, rows: list<array{line: JournalLine, in: Money, out: Money, balance: Money, counterparts: string}>, closing: Money, total_in: Money, total_out: Money}
     */
    public function statement(LedgerAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = $this->balance($account, $from->copy()->subDay());
        $debitNormal = $account->type->isDebitNormal();

        $lines = JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->id)
            ->whereBetween('journal_entries.entry_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.line_no')
            ->with(['entry.lines.account', 'entry.lines.party', 'entry.creator'])
            ->get();

        $running = $opening;
        $totalIn = Money::zero();
        $totalOut = Money::zero();
        $rows = [];

        foreach ($lines as $line) {
            $in = $debitNormal ? $line->debit : $line->credit;
            $out = $debitNormal ? $line->credit : $line->debit;
            $running = $running->add($in)->subtract($out);
            $totalIn = $totalIn->add($in);
            $totalOut = $totalOut->add($out);

            $counterparts = $line->entry->lines
                ->where('ledger_account_id', '!=', $account->id)
                ->map(fn (JournalLine $other) => JournalPresenter::lineName($other))
                ->unique()
                ->implode(', ');

            $rows[] = ['line' => $line, 'in' => $in, 'out' => $out, 'balance' => $running, 'counterparts' => $counterparts];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $running, 'total_in' => $totalIn, 'total_out' => $totalOut];
    }

    /**
     * Net balance per party across receivables and payables: positive means they owe us,
     * negative means we owe them.
     *
     * @param  list<int>  $partyIds
     * @return array<int, Money>
     */
    public function partyBalances(array $partyIds, ?CarbonInterface $asOf = null): array
    {
        $result = [];
        foreach ($partyIds as $id) {
            $result[$id] = Money::zero();
        }

        if ($partyIds === []) {
            return $result;
        }

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.kiln_id', $this->currentKiln->id())
            ->whereIn('journal_lines.party_id', $partyIds)
            ->when($asOf, fn ($q) => $q->where('journal_entries.entry_date', '<=', $asOf->format('Y-m-d')))
            ->groupBy('journal_lines.party_id')
            ->select('journal_lines.party_id')
            ->selectRaw('SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->party_id] = Money::fromPaisa((int) $row->net);
        }

        return $result;
    }

    /**
     * Totals across all parties: what others owe us (receivable) and what we owe others
     * (payable). Each party is netted first, so one party is never counted on both sides.
     *
     * @return array{receivable: Money, payable: Money}
     */
    public function partyTotals(): array
    {
        $perParty = DB::table('journal_lines')
            ->where('kiln_id', $this->currentKiln->id())
            ->whereNotNull('party_id')
            ->groupBy('party_id')
            ->selectRaw('SUM(debit) - SUM(credit) AS net');

        $totals = DB::query()
            ->fromSub($perParty, 'p')
            ->selectRaw('COALESCE(SUM(CASE WHEN net > 0 THEN net ELSE 0 END), 0) AS receivable')
            ->selectRaw('COALESCE(SUM(CASE WHEN net < 0 THEN -net ELSE 0 END), 0) AS payable')
            ->first();

        return [
            'receivable' => Money::fromPaisa((int) ($totals->receivable ?? 0)),
            'payable' => Money::fromPaisa((int) ($totals->payable ?? 0)),
        ];
    }

    /**
     * Statement of one party for a period. Debit = they owe us more (e.g. a sale);
     * credit = they owe us less, or we owe them more (e.g. a payment received, a purchase).
     *
     * @return array{opening: Money, rows: list<array{line: JournalLine, debit: Money, credit: Money, balance: Money}>, closing: Money, total_debit: Money, total_credit: Money}
     */
    public function partyStatement(Party $party, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = $this->partyBalances([$party->id], $from->copy()->subDay())[$party->id];

        $lines = JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.party_id', $party->id)
            ->whereBetween('journal_entries.entry_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.line_no')
            ->with(['entry.lines.account', 'entry.lines.party', 'entry.creator'])
            ->get();

        $running = $opening;
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();
        $rows = [];

        foreach ($lines as $line) {
            $running = $running->add($line->debit)->subtract($line->credit);
            $totalDebit = $totalDebit->add($line->debit);
            $totalCredit = $totalCredit->add($line->credit);
            $rows[] = ['line' => $line, 'debit' => $line->debit, 'credit' => $line->credit, 'balance' => $running];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $running, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    private function sumsQuery(?CarbonInterface $asOf): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.kiln_id', $this->currentKiln->id())
            ->when($asOf, fn ($q) => $q->where('journal_entries.entry_date', '<=', $asOf->format('Y-m-d')))
            ->groupBy('journal_lines.ledger_account_id')
            ->select('journal_lines.ledger_account_id')
            ->selectRaw('SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits');
    }
}
