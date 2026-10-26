<?php

namespace App\Labour;

use App\Accounting\StatementPager;
use App\Enums\JournalType;
use App\Models\JournalLine;
use App\Models\LabourRate;
use App\Models\Worker;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views of workers' accounts, all from the journal (the "Wages payable" lines that
 * name the worker): balances, statements, the period figures of a settlement, and rates.
 *
 * Sign: positive means the kiln owes the worker; negative means the worker owes the kiln.
 */
class WorkerLedger
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    /**
     * @param  list<int>  $workerIds
     * @return array<int, Money>
     */
    public function balances(array $workerIds, ?CarbonInterface $asOf = null): array
    {
        $result = [];
        foreach ($workerIds as $id) {
            $result[$id] = Money::zero();
        }

        if ($workerIds === []) {
            return $result;
        }

        $rows = DB::table('journal_lines')
            ->where('journal_lines.kiln_id', $this->currentKiln->id())
            ->whereIn('journal_lines.worker_id', $workerIds)
            ->when($asOf, fn ($q) => $q->where('journal_lines.entry_date', '<=', $asOf->format('Y-m-d')))
            ->groupBy('journal_lines.worker_id')
            ->select('journal_lines.worker_id')
            ->selectRaw('SUM(journal_lines.credit) - SUM(journal_lines.debit) AS net')
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->worker_id] = Money::fromPaisa((int) $row->net);
        }

        return $result;
    }

    /**
     * Totals across workers: what the kiln owes them, and what they owe the kiln (advances).
     *
     * @return array{owed_to_workers: Money, advances_out: Money}
     */
    public function totals(): array
    {
        $perWorker = DB::table('journal_lines')
            ->where('kiln_id', $this->currentKiln->id())
            ->whereNotNull('worker_id')
            ->groupBy('worker_id')
            ->selectRaw('SUM(credit) - SUM(debit) AS net');

        $totals = DB::query()->fromSub($perWorker, 'w')
            ->selectRaw('COALESCE(SUM(CASE WHEN net > 0 THEN net ELSE 0 END), 0) AS owed')
            ->selectRaw('COALESCE(SUM(CASE WHEN net < 0 THEN -net ELSE 0 END), 0) AS advances')
            ->first();

        return [
            'owed_to_workers' => Money::fromPaisa((int) ($totals->owed ?? 0)),
            'advances_out' => Money::fromPaisa((int) ($totals->advances ?? 0)),
        ];
    }

    /**
     * What happened on each worker's account in a period: the balance brought forward, earned,
     * advances and payments (net of money paid back), deductions, and the balance at the end.
     * A reversal counts against what it reverses.
     *
     * @param  list<int>  $workerIds
     * @return array<int, array{brought_forward: Money, earned: Money, advances: Money, deductions: Money, payable: Money}>
     */
    public function periodSummary(array $workerIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = $this->balances($workerIds, $from->copy()->subDay());
        $sums = [];
        foreach ($workerIds as $id) {
            $sums[$id] = ['earned' => 0, 'advances' => 0, 'deductions' => 0];
        }

        if ($workerIds !== []) {
            $rows = DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.kiln_id', $this->currentKiln->id())
                ->leftJoin('journal_entries as o', 'o.id', '=', 'e.reversal_of_id')
                ->where('l.kiln_id', $this->currentKiln->id())
                ->whereIn('l.worker_id', $workerIds)
                ->whereBetween('l.entry_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->groupBy('l.worker_id', DB::raw('COALESCE(o.type, e.type)'))
                ->select('l.worker_id')
                ->selectRaw('COALESCE(o.type, e.type) AS kind')
                ->selectRaw('SUM(l.credit) - SUM(l.debit) AS net')
                ->get();

            foreach ($rows as $row) {
                $bucket = match (JournalType::tryFrom($row->kind)) {
                    JournalType::Wages => 'earned',
                    JournalType::WorkerDeduction => 'deductions',
                    default => 'advances', // advances, earlier payments, money paid back
                };
                // Earned is money owed to the worker (credit); the others are taken off (debit).
                $sums[(int) $row->worker_id][$bucket] += $bucket === 'earned' ? (int) $row->net : -(int) $row->net;
            }
        }

        $result = [];
        foreach ($workerIds as $id) {
            $payable = $opening[$id]->paisa + $sums[$id]['earned'] - $sums[$id]['advances'] - $sums[$id]['deductions'];
            $result[$id] = [
                'brought_forward' => $opening[$id],
                'earned' => Money::fromPaisa($sums[$id]['earned']),
                'advances' => Money::fromPaisa($sums[$id]['advances']),
                'deductions' => Money::fromPaisa($sums[$id]['deductions']),
                'payable' => Money::fromPaisa($payable),
            ];
        }

        return $result;
    }

    /**
     * A worker's statement for a period: balance brought forward, each earning, advance,
     * deduction and payment with the running balance, and the closing balance. Paged like the
     * account statement (LedgerQueries::statement()).
     *
     * @return array{opening: Money, brought_forward: Money, rows: list<array{line: JournalLine, earned: Money, taken: Money, balance: Money}>, closing: Money, total_earned: Money, total_taken: Money, page: int, pages: int, count: int}
     */
    public function statement(Worker $worker, CarbonInterface $from, CarbonInterface $to, ?int $page = null, ?int $perPage = null): array
    {
        $opening = $this->balances([$worker->id], $from->copy()->subDay())[$worker->id];

        $slice = StatementPager::slice(JournalLine::query()
            ->where('journal_lines.worker_id', $worker->id)
            ->whereBetween('journal_lines.entry_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]), $page, $perPage);
        $slice['lines']->load(['entry.lines.account', 'entry.lines.party', 'entry.lines.worker', 'entry.creator']);

        $broughtForward = $opening->add(Money::fromPaisa($slice['before_credit'] - $slice['before_debit']));
        $running = $broughtForward;
        $rows = [];

        foreach ($slice['lines'] as $line) {
            $running = $running->add($line->credit)->subtract($line->debit);
            $rows[] = ['line' => $line, 'earned' => $line->credit, 'taken' => $line->debit, 'balance' => $running];
        }

        return [
            'opening' => $opening,
            'brought_forward' => $broughtForward,
            'rows' => $rows,
            'closing' => $opening->add(Money::fromPaisa($slice['credit'] - $slice['debit'])),
            'total_earned' => Money::fromPaisa($slice['credit']),
            'total_taken' => Money::fromPaisa($slice['debit']),
            'page' => $slice['page'],
            'pages' => $slice['pages'],
            'count' => $slice['count'],
        ];
    }

    /**
     * The rate of a day for a worker: their own latest rate on or before it, otherwise their
     * category's. Null when neither has one.
     */
    public function rateFor(Worker $worker, CarbonInterface $date): ?LabourRate
    {
        $query = fn (?int $workerId) => LabourRate::query()
            ->where('labour_category_id', $worker->labour_category_id)
            ->when($workerId, fn ($q) => $q->where('worker_id', $workerId), fn ($q) => $q->whereNull('worker_id'))
            ->where('effective_from', '<=', $date->format('Y-m-d'))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $query($worker->id) ?? $query(null);
    }
}
