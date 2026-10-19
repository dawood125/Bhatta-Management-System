<?php

namespace App\Accounting;

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

        return $problems;
    }
}
