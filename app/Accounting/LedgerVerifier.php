<?php

namespace App\Accounting;

use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Independent re-check of the whole ledger, straight from the database (all kilns).
 * Returns human-readable problems; an empty list means the books are consistent.
 */
class LedgerVerifier
{
    /**
     * @return list<string>
     */
    public function verify(): array
    {
        $problems = [];

        // 1. Every entry balances, matches its stored total, and has at least two lines.
        $entries = DB::table('journal_entries as e')
            ->leftJoin('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->groupBy('e.id', 'e.number', 'e.total')
            ->select('e.id', 'e.number', 'e.total')
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS debits, COALESCE(SUM(l.credit), 0) AS credits, COUNT(l.id) AS line_count')
            ->get();

        foreach ($entries as $e) {
            if ((int) $e->debits !== (int) $e->credits) {
                $problems[] = "Entry {$e->number}: debits {$e->debits} do not equal credits {$e->credits}.";
            }
            if ((int) $e->debits !== (int) $e->total) {
                $problems[] = "Entry {$e->number}: stored total {$e->total} does not match its lines ({$e->debits}).";
            }
            if ((int) $e->line_count < 2) {
                $problems[] = "Entry {$e->number}: has fewer than two lines.";
            }
        }

        // 2. Each line carries exactly one positive side.
        $badLines = DB::table('journal_lines')
            ->whereRaw('NOT ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))')
            ->pluck('id');
        foreach ($badLines as $id) {
            $problems[] = "Line #{$id}: must be a positive debit or a positive credit.";
        }

        // 3. Lines, entries and accounts belong to the same kiln.
        $crossKiln = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->whereRaw('NOT (l.kiln_id = e.kiln_id AND l.kiln_id = a.kiln_id)')
            ->pluck('l.id');
        foreach ($crossKiln as $id) {
            $problems[] = "Line #{$id}: kiln does not match its entry or account.";
        }

        // 3b. Each line carries its entry's date (balances on a date are read from the lines).
        $wrongDate = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereColumn('l.entry_date', '!=', 'e.entry_date')
            ->pluck('l.id');
        foreach ($wrongDate as $id) {
            $problems[] = "Line #{$id}: its date does not match its entry's date.";
        }

        // 4. Reversal links are consistent in both directions.
        $reversedWithoutReversal = DB::table('journal_entries as e')
            ->where('e.status', 'reversed')
            ->whereNotExists(fn ($q) => $q->from('journal_entries as r')->whereColumn('r.reversal_of_id', 'e.id'))
            ->pluck('e.number');
        foreach ($reversedWithoutReversal as $number) {
            $problems[] = "Entry {$number}: marked reversed but no reversal entry exists.";
        }

        $reversalOfPosted = DB::table('journal_entries as r')
            ->join('journal_entries as e', 'e.id', '=', 'r.reversal_of_id')
            ->where('e.status', '!=', 'reversed')
            ->pluck('r.number');
        foreach ($reversalOfPosted as $number) {
            $problems[] = "Reversal {$number}: the original entry is not marked reversed.";
        }

        // 5. Cash/bank opening balances match their opening entries.
        $treasury = DB::table('treasury_accounts as t')
            ->join('ledger_accounts as a', 'a.id', '=', 't.ledger_account_id')
            ->leftJoin('journal_lines as l', function ($join) {
                $join->on('l.journal_entry_id', '=', 't.opening_entry_id')->on('l.ledger_account_id', '=', 't.ledger_account_id');
            })
            ->select('t.id', 't.name', 't.opening_balance', 't.opening_entry_id', 'a.type', 'l.debit', 'l.credit')
            ->get();

        foreach ($treasury as $t) {
            $posted = $t->opening_entry_id === null ? 0
                : ((int) $t->debit - (int) $t->credit) * ($t->type === 'asset' ? 1 : -1);

            if ((int) $t->opening_balance !== $posted) {
                $problems[] = "Cash/bank account \"{$t->name}\": opening balance {$t->opening_balance} does not match its opening entry ({$posted}).";
            }
        }

        // 6. Receivable/payable lines always name a party; other lines never do; parties stay in their kiln.
        $partyRule = DB::table('journal_lines as l')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('parties as p', 'p.id', '=', 'l.party_id')
            ->where(function ($q) {
                $q->where(fn ($q) => $q->whereIn('a.system_key', ['receivables', 'payables'])->whereNull('l.party_id'))
                    ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('a.system_key')->orWhereNotIn('a.system_key', ['receivables', 'payables']))->whereNotNull('l.party_id'))
                    ->orWhere(fn ($q) => $q->whereNotNull('l.party_id')->whereColumn('p.kiln_id', '!=', 'l.kiln_id'));
            })
            ->pluck('l.id');
        foreach ($partyRule as $id) {
            $problems[] = "Line #{$id}: party missing on a receivable/payable line, present on another account, or from another kiln.";
        }

        // 7. Party opening balances match their opening entries.
        $parties = DB::table('parties as p')
            ->leftJoin('journal_lines as l', function ($join) {
                $join->on('l.journal_entry_id', '=', 'p.opening_entry_id')->on('l.party_id', '=', 'p.id');
            })
            ->groupBy('p.id', 'p.name', 'p.opening_balance', 'p.opening_entry_id')
            ->select('p.id', 'p.name', 'p.opening_balance', 'p.opening_entry_id')
            ->selectRaw('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS posted')
            ->get();

        foreach ($parties as $p) {
            $posted = $p->opening_entry_id === null ? 0 : (int) $p->posted;

            if ((int) $p->opening_balance !== $posted) {
                $problems[] = "Party \"{$p->name}\": opening balance {$p->opening_balance} does not match its opening entry ({$posted}).";
            }
        }

        // 8. Each expense matches its entry: same kiln and date, the amount debited to the category's
        //    expense account and credited to the cash/bank account. Every expense entry has an expense.
        $expenses = DB::table('expenses as x')
            ->join('journal_entries as e', 'e.id', '=', 'x.journal_entry_id')
            ->join('expense_categories as c', 'c.id', '=', 'x.expense_category_id')
            ->join('treasury_accounts as t', 't.id', '=', 'x.treasury_account_id')
            ->select('x.id', 'e.number', 'e.type', 'e.total', 'x.amount')
            ->selectRaw('(e.entry_date = x.expense_date AND e.kiln_id = x.kiln_id AND c.kiln_id = x.kiln_id AND t.kiln_id = x.kiln_id) AS same_place')
            ->selectRaw('(SELECT COALESCE(SUM(l.debit), 0) FROM journal_lines l WHERE l.journal_entry_id = x.journal_entry_id AND l.ledger_account_id = c.ledger_account_id) AS expense_debit')
            ->selectRaw('(SELECT COALESCE(SUM(l.credit), 0) FROM journal_lines l WHERE l.journal_entry_id = x.journal_entry_id AND l.ledger_account_id = t.ledger_account_id) AS cash_credit')
            ->get();

        foreach ($expenses as $x) {
            $amount = (int) $x->amount;
            if ($x->type !== 'expense' || ! (bool) $x->same_place || (int) $x->total !== $amount
                || (int) $x->expense_debit !== $amount || (int) $x->cash_credit !== $amount) {
                $problems[] = "Expense #{$x->id} ({$x->number}): does not match its journal entry.";
            }
        }

        $expenseEntriesWithoutExpense = DB::table('journal_entries as e')
            ->where('e.type', 'expense')
            ->whereNotExists(fn ($q) => $q->from('expenses as x')->whereColumn('x.journal_entry_id', 'e.id'))
            ->pluck('e.number');
        foreach ($expenseEntriesWithoutExpense as $number) {
            $problems[] = "Entry {$number}: an expense entry without its expense record.";
        }

        // 9. Vouchers: line 1 is the voucher's cash/bank account, credited by a payment and debited
        //    by a receipt, with the full voucher total.
        $vouchers = DB::table('journal_entries as e')
            ->join('journal_lines as l', fn ($join) => $join->on('l.journal_entry_id', '=', 'e.id')->where('l.line_no', 1))
            ->leftJoin('treasury_accounts as t', 't.ledger_account_id', '=', 'l.ledger_account_id')
            ->whereIn('e.type', ['payment', 'receipt'])
            ->select('e.number', 'e.type', 'e.total', 'l.debit', 'l.credit', 't.id as treasury_id')
            ->get();

        foreach ($vouchers as $v) {
            $side = $v->type === 'payment' ? (int) $v->credit : (int) $v->debit;
            if ($v->treasury_id === null || $side !== (int) $v->total) {
                $problems[] = "Voucher {$v->number}: line 1 must move the full total through a cash/bank account.";
            }
        }

        return [...$problems, ...$this->verifyMaterials(), ...$this->verifyProduction(), ...$this->verifyLabour(), ...$this->verifySales()];
    }

    /**
     * @return list<string>
     */
    private function verifyLabour(): array
    {
        $problems = [];

        // 16. Wages payable lines always name a worker; other lines never do; workers stay in their kiln.
        $workerRule = DB::table('journal_lines as l')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('workers as w', 'w.id', '=', 'l.worker_id')
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('a.system_key', 'wages_payable')->whereNull('l.worker_id'))
                    ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('a.system_key')->orWhere('a.system_key', '!=', 'wages_payable'))->whereNotNull('l.worker_id'))
                    ->orWhere(fn ($q) => $q->whereNotNull('l.worker_id')->whereColumn('w.kiln_id', '!=', 'l.kiln_id'));
            })
            ->pluck('l.id');
        foreach ($workerRule as $id) {
            $problems[] = "Line #{$id}: worker missing on a wages line, present on another account, or from another kiln.";
        }

        // 17. Each work entry matches its entry: the amount credited to that worker. Every wages entry has one.
        $work = DB::table('work_entries as x')
            ->join('journal_entries as e', 'e.id', '=', 'x.journal_entry_id')
            ->select('x.id', 'e.number', 'e.type', 'e.total', 'x.amount')
            ->selectRaw('(SELECT COALESCE(SUM(l.credit), 0) FROM journal_lines l WHERE l.journal_entry_id = x.journal_entry_id AND l.worker_id = x.worker_id) AS credited')
            ->get();
        foreach ($work as $w) {
            if ($w->type !== 'wages' || (int) $w->total !== (int) $w->amount || (int) $w->credited !== (int) $w->amount) {
                $problems[] = "Work entry #{$w->id} ({$w->number}): does not match its journal entry.";
            }
        }

        $wagesWithoutWork = DB::table('journal_entries as e')
            ->where('e.type', 'wages')
            ->whereNotExists(fn ($q) => $q->from('work_entries as x')->whereColumn('x.journal_entry_id', 'e.id'))
            ->pluck('e.number');
        foreach ($wagesWithoutWork as $number) {
            $problems[] = "Entry {$number}: a wages entry without its work entry.";
        }

        // 18. Settlements add up: lines paid = total paid = the payment entry; payable − paid = remaining.
        $settlements = DB::table('settlements as s')
            ->leftJoin('journal_entries as e', 'e.id', '=', 's.journal_entry_id')
            ->select('s.number', 's.total_paid', 's.journal_entry_id', 'e.total as entry_total')
            ->selectRaw('(SELECT COALESCE(SUM(paid), 0) FROM settlement_lines WHERE settlement_id = s.id) AS lines_paid')
            // "paid" is unsigned: cast it, or a worker who owes the kiln (negative payable) overflows.
            ->selectRaw('(SELECT COUNT(*) FROM settlement_lines WHERE settlement_id = s.id AND payable - CAST(paid AS SIGNED) <> remaining) AS bad_lines')
            ->get();
        foreach ($settlements as $s) {
            $entryTotal = $s->journal_entry_id === null ? 0 : (int) $s->entry_total;
            if ((int) $s->lines_paid !== (int) $s->total_paid || $entryTotal !== (int) $s->total_paid || (int) $s->bad_lines > 0) {
                $problems[] = "Settlement {$s->number}: its lines, total and payment entry do not add up.";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function verifySales(): array
    {
        $problems = [];

        // 19. Each sale matches its invoice entry: same kiln, date and number, the customer owes
        //     the sale total, and the total is the sum of its lines.
        $sales = DB::table('sales as s')
            ->leftJoin('journal_entries as e', 'e.id', '=', 's.journal_entry_id')
            ->select('s.number', 's.kiln_id', 's.sale_date', 's.total', 's.customer_id', 'e.type', 'e.kiln_id as entry_kiln', 'e.entry_date', 'e.number as entry_number', 'e.total as entry_total')
            ->selectRaw('(SELECT COALESCE(SUM(amount), 0) FROM sale_lines WHERE sale_id = s.id) AS lines_total')
            ->selectRaw('(SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE journal_entry_id = s.journal_entry_id AND party_id = s.customer_id) AS customer_debit')
            ->get();
        foreach ($sales as $sale) {
            if ($sale->type !== 'sale' || (int) $sale->entry_kiln !== (int) $sale->kiln_id || $sale->entry_number !== $sale->number
                || substr((string) $sale->entry_date, 0, 10) !== substr((string) $sale->sale_date, 0, 10)
                || (int) $sale->entry_total !== (int) $sale->total || (int) $sale->lines_total !== (int) $sale->total || (int) $sale->customer_debit !== (int) $sale->total) {
                $problems[] = "Sale {$sale->number}: it does not match its invoice entry or its lines.";
            }
        }

        // 20. Each line's amount is rate × quantity ÷ basis (rounded half up), less its discount.
        foreach (DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->select('s.number', 'l.line_no', 'l.quantity', 'l.rate', 'l.rate_per', 'l.discount', 'l.amount')->get() as $line) {
            $gross = Money::fromPaisa((int) $line->rate)->timesQuantity(Quantity::fromMilli((int) $line->quantity)->toDecimal(), max(1, (int) $line->rate_per));
            if ((int) $line->discount > $gross->paisa || $gross->paisa - (int) $line->discount !== (int) $line->amount) {
                $problems[] = "Sale {$line->number}, line {$line->line_no}: the amount is not rate × quantity less the discount.";
            }
        }

        // 21. Each delivery took its lines out of stock (one movement per line, same quantities),
        //     a reversed delivery put every one of them back, and its carriage entry matches.
        $dispatches = DB::table('sale_dispatches as d')
            ->join('sales as s', 's.id', '=', 'd.sale_id')
            ->leftJoin('journal_entries as e', 'e.id', '=', 'd.carriage_entry_id')
            ->select('d.number', 'd.reversed_at', 'd.carriage', 'd.carriage_entry_id', 'd.driver_id', 's.customer_id', 'e.type as entry_type', 'e.total as entry_total', 'e.status as entry_status')
            ->selectRaw('(SELECT COUNT(*) FROM sale_dispatch_lines WHERE sale_dispatch_id = d.id) AS line_count')
            ->selectRaw('(SELECT COALESCE(SUM(quantity), 0) FROM sale_dispatch_lines WHERE sale_dispatch_id = d.id) AS line_quantity')
            ->selectRaw('(SELECT COUNT(*) FROM brick_movements WHERE sale_dispatch_id = d.id AND reversal_of_id IS NULL AND stage_to IS NULL) AS own')
            ->selectRaw('(SELECT COALESCE(SUM(quantity), 0) FROM brick_movements WHERE sale_dispatch_id = d.id AND reversal_of_id IS NULL) AS own_quantity')
            ->selectRaw('(SELECT COUNT(*) FROM brick_movements WHERE sale_dispatch_id = d.id AND reversal_of_id IS NOT NULL) AS mirrors')
            ->selectRaw('(SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE journal_entry_id = d.carriage_entry_id AND party_id = s.customer_id) AS customer_debit')
            ->get();
        foreach ($dispatches as $d) {
            if ((int) $d->own !== (int) $d->line_count || (int) $d->own_quantity !== (int) $d->line_quantity) {
                $problems[] = "Delivery {$d->number}: its stock movements do not match its lines.";
            }

            if ((int) $d->mirrors !== ($d->reversed_at !== null ? (int) $d->own : 0)) {
                $problems[] = "Delivery {$d->number}: its reversal movements are incomplete.";
            }

            $carriageOk = $d->carriage_entry_id === null
                ? (int) $d->carriage === 0
                : $d->entry_type === 'carriage' && $d->driver_id !== null && (int) $d->entry_total === (int) $d->carriage && (int) $d->customer_debit === (int) $d->carriage
                    && ($d->reversed_at === null) === ($d->entry_status === 'posted');
            if (! $carriageOk) {
                $problems[] = "Delivery {$d->number}: its carriage does not match its entry.";
            }
        }

        // 22. No sale line was delivered more than was ordered.
        $over = DB::table('sale_lines as l')
            ->join('sales as s', 's.id', '=', 'l.sale_id')
            ->select('s.number', 'l.line_no', 'l.quantity')
            ->selectRaw('(SELECT COALESCE(SUM(dl.quantity), 0) FROM sale_dispatch_lines dl JOIN sale_dispatches d ON d.id = dl.sale_dispatch_id WHERE dl.sale_line_id = l.id AND d.reversed_at IS NULL) AS delivered')
            ->get()
            ->filter(fn ($line) => (int) $line->delivered > (int) $line->quantity);
        foreach ($over as $line) {
            $problems[] = "Sale {$line->number}, line {$line->line_no}: more was delivered than ordered.";
        }

        // 23. A cancelled sale has its invoice reversed and no delivery left; a closed sale's credit
        //     note is a credit note for that customer.
        $states = DB::table('sales as s')
            ->join('journal_entries as e', 'e.id', '=', 's.journal_entry_id')
            ->leftJoin('journal_entries as c', 'c.id', '=', 's.credit_entry_id')
            ->select('s.number', 's.status', 's.cancelled_at', 's.closed_at', 's.credit_entry_id', 'e.status as entry_status', 'c.type as credit_type')
            ->selectRaw('(SELECT COUNT(*) FROM sale_dispatches WHERE sale_id = s.id AND reversed_at IS NULL) AS live_dispatches')
            ->selectRaw('(SELECT COALESCE(SUM(credit), 0) FROM journal_lines WHERE journal_entry_id = s.credit_entry_id AND party_id = s.customer_id) AS credit_to_customer')
            ->selectRaw('(SELECT total FROM journal_entries WHERE id = s.credit_entry_id) AS credit_total')
            ->get();
        foreach ($states as $state) {
            $cancelled = $state->status === 'cancelled';
            if ($cancelled !== ($state->cancelled_at !== null) || $cancelled !== ($state->entry_status === 'reversed') || ($cancelled && (int) $state->live_dispatches > 0)) {
                $problems[] = "Sale {$state->number}: its cancellation is inconsistent.";
            }

            if ($state->credit_entry_id !== null && ($state->closed_at === null || $state->credit_type !== 'sale_credit' || (int) $state->credit_to_customer !== (int) $state->credit_total)) {
                $problems[] = "Sale {$state->number}: its credit note is inconsistent.";
            }
        }

        // 24. Money received for a sale (with it, or from its page) is a posted-or-reversed receipt
        //     that credits that sale's customer.
        $receipts = DB::table('sales as s')->whereNotNull('s.receipt_entry_id')->select('s.number', 's.customer_id', 's.receipt_entry_id as entry_id')
            ->unionAll(DB::table('sale_receipts as r')->join('sales as s', 's.id', '=', 'r.sale_id')->select('s.number', 's.customer_id', 'r.journal_entry_id as entry_id'));
        $badReceipts = DB::query()->fromSub($receipts, 'x')
            ->join('journal_entries as e', 'e.id', '=', 'x.entry_id')
            ->where(fn ($q) => $q->where('e.type', '!=', 'receipt')
                ->orWhereNotExists(fn ($l) => $l->from('journal_lines as l')->whereColumn('l.journal_entry_id', 'e.id')->whereColumn('l.party_id', 'x.customer_id')->where('l.credit', '>', 0)))
            ->pluck('x.number');
        foreach ($badReceipts as $number) {
            $problems[] = "Sale {$number}: money received for it is not a receipt from its customer.";
        }

        return $problems;
    }

    /**
     * Brick stock has no money side, but the same discipline: never negative, graded where it
     * must be, and reversals complete.
     *
     * @return list<string>
     */
    private function verifyProduction(): array
    {
        $problems = [];

        // 13. Saleable movements name their grade.
        $ungraded = DB::table('brick_movements')
            ->where(fn ($q) => $q->where('stage_to', 'saleable')->orWhere('stage_from', 'saleable'))
            ->whereNull('product_id')
            ->pluck('id');
        foreach ($ungraded as $id) {
            $problems[] = "Brick movement #{$id}: saleable bricks must name their grade.";
        }

        // 14. No stage (or grade) holds fewer than zero bricks.
        $in = DB::table('brick_movements')->whereNotNull('stage_to')
            ->groupBy('kiln_id', 'stage_to', DB::raw("CASE WHEN stage_to = 'saleable' THEN product_id END"))
            ->selectRaw("kiln_id, stage_to AS stage, CASE WHEN stage_to = 'saleable' THEN product_id END AS product, SUM(quantity) AS quantity");
        $out = DB::table('brick_movements')->whereNotNull('stage_from')
            ->groupBy('kiln_id', 'stage_from', DB::raw("CASE WHEN stage_from = 'saleable' THEN product_id END"))
            ->selectRaw("kiln_id, stage_from AS stage, CASE WHEN stage_from = 'saleable' THEN product_id END AS product, -SUM(quantity) AS quantity");
        $levels = DB::query()->fromSub($in->unionAll($out), 's')
            ->groupBy('kiln_id', 'stage', 'product')
            ->selectRaw('kiln_id, stage, product, SUM(quantity) AS quantity')
            ->havingRaw('SUM(quantity) < 0')
            ->get();
        foreach ($levels as $level) {
            $problems[] = "Kiln #{$level->kiln_id}: stage {$level->stage}".($level->product !== null ? " (product #{$level->product})" : '')." holds {$level->quantity}.";
        }

        // 15. A reversed entry has a mirror for every movement; an entry that counts has none.
        $entries = DB::table('production_entries as p')
            ->select('p.number', 'p.reversed_at')
            ->selectRaw('(SELECT COUNT(*) FROM brick_movements m WHERE m.production_entry_id = p.id AND m.reversal_of_id IS NULL) AS own')
            ->selectRaw('(SELECT COUNT(*) FROM brick_movements m WHERE m.production_entry_id = p.id AND m.reversal_of_id IS NOT NULL) AS mirrors')
            ->get();
        foreach ($entries as $e) {
            $expected = $e->reversed_at !== null ? (int) $e->own : 0;
            if ((int) $e->mirrors !== $expected) {
                $problems[] = "Production entry {$e->number}: its reversal movements are incomplete.";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function verifyMaterials(): array
    {
        $problems = [];

        // 10. In every entry, the value of the stock movements equals what the entry moves through
        //     "Material stock" (so the account always equals the value of the stock).
        $movementNets = DB::table('material_movements')
            ->groupBy('journal_entry_id')
            ->selectRaw('journal_entry_id, SUM(value_in) - SUM(value_out) AS net, kiln_id')
            ->groupBy('kiln_id')
            ->get()
            ->keyBy('journal_entry_id');

        $ledgerNets = DB::table('journal_lines as l')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.system_key', 'material_stock')
            ->groupBy('l.journal_entry_id', 'l.kiln_id')
            ->selectRaw('l.journal_entry_id, SUM(l.debit) - SUM(l.credit) AS net, l.kiln_id')
            ->get()
            ->keyBy('journal_entry_id');

        foreach ($movementNets->keys()->merge($ledgerNets->keys())->unique() as $entryId) {
            $stock = (int) ($movementNets[$entryId]->net ?? 0);
            $ledger = (int) ($ledgerNets[$entryId]->net ?? 0);
            $sameKiln = ! isset($movementNets[$entryId], $ledgerNets[$entryId]) || (int) $movementNets[$entryId]->kiln_id === (int) $ledgerNets[$entryId]->kiln_id;

            if ($stock !== $ledger || ! $sameKiln) {
                $number = DB::table('journal_entries')->where('id', $entryId)->value('number');
                $problems[] = "Entry {$number}: stock movements ({$stock}) do not match the Material stock account ({$ledger}).";
            }
        }

        // 11. No material is below zero, and an empty material carries no value.
        $levels = DB::table('material_movements as m')
            ->join('materials as x', 'x.id', '=', 'm.material_id')
            ->groupBy('m.material_id', 'x.name')
            ->select('x.name')
            ->selectRaw('SUM(m.quantity_in) - SUM(m.quantity_out) AS quantity, SUM(m.value_in) - SUM(m.value_out) AS value')
            ->get();

        foreach ($levels as $level) {
            if ((int) $level->quantity < 0 || (int) $level->value < 0 || ((int) $level->quantity === 0 && (int) $level->value !== 0)) {
                $problems[] = "Material \"{$level->name}\": stock {$level->quantity} with value {$level->value} is not possible.";
            }
        }

        // 12. Document totals add up and match their entries.
        $purchases = DB::table('material_purchases as p')
            ->join('journal_entries as e', 'e.id', '=', 'p.journal_entry_id')
            ->select('p.id', 'e.number', 'e.total as entry_total', 'p.goods_total', 'p.charges_total', 'p.total')
            ->selectRaw('(SELECT COALESCE(SUM(amount), 0) FROM material_purchase_lines WHERE material_purchase_id = p.id) AS line_amounts')
            ->selectRaw('(SELECT COALESCE(SUM(cost), 0) FROM material_purchase_lines WHERE material_purchase_id = p.id) AS line_costs')
            ->selectRaw('(SELECT COALESCE(SUM(amount + charges - cost), 0) FROM material_purchase_lines WHERE material_purchase_id = p.id) AS line_gaps')
            ->selectRaw('(SELECT COALESCE(SUM(amount), 0) FROM material_purchase_charges WHERE material_purchase_id = p.id) AS charge_amounts')
            ->get();

        foreach ($purchases as $p) {
            $ok = (int) $p->line_amounts === (int) $p->goods_total
                && (int) $p->charge_amounts === (int) $p->charges_total
                && (int) $p->line_gaps === 0
                && (int) $p->line_costs === (int) $p->total
                && (int) $p->goods_total + (int) $p->charges_total === (int) $p->total
                && (int) $p->entry_total === (int) $p->total;

            if (! $ok) {
                $problems[] = "Purchase {$p->number}: its lines, charges and entry do not add up.";
            }
        }

        $usages = DB::table('material_usages as u')
            ->join('journal_entries as e', 'e.id', '=', 'u.journal_entry_id')
            ->select('e.number', 'e.total as entry_total', 'u.total_cost')
            ->selectRaw('(SELECT COALESCE(SUM(value_out), 0) FROM material_movements WHERE material_usage_id = u.id AND reversal_of_id IS NULL) AS moved')
            ->get();

        foreach ($usages as $u) {
            if ((int) $u->moved !== (int) $u->total_cost || (int) $u->entry_total !== (int) $u->total_cost) {
                $problems[] = "Material usage {$u->number}: its stock movements and entry do not add up.";
            }
        }

        $adjustments = DB::table('material_adjustments as a')
            ->join('journal_entries as e', 'e.id', '=', 'a.journal_entry_id')
            ->select('e.number', 'e.total as entry_total', 'a.value')
            ->selectRaw('(SELECT COALESCE(SUM(value_in + value_out), 0) FROM material_movements WHERE material_adjustment_id = a.id AND reversal_of_id IS NULL) AS moved')
            ->get();

        foreach ($adjustments as $a) {
            if ((int) $a->moved !== (int) $a->value || (int) $a->entry_total !== (int) $a->value) {
                $problems[] = "Stock adjustment {$a->number}: its stock movement and entry do not add up.";
            }
        }

        return $problems;
    }
}
