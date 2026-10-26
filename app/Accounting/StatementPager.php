<?php

namespace App\Accounting;

use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One page of a statement: the lines of a period in date order, a page at a time, with the
 * debits and credits of the whole period and of everything before the page, so that running
 * balances are right on every page. Without a page size it returns every line of the period
 * (PDFs). With a page size and no page, it returns the last page: the most recent lines.
 */
final class StatementPager
{
    public const PER_PAGE = 100;

    /** Rows per page on screen (config app.statement_rows, 100 unless set). */
    public static function perPage(): int
    {
        return max(1, (int) config('app.statement_rows', self::PER_PAGE));
    }

    /**
     * @param  Builder<JournalLine>  $lines  the period's lines, in any order
     * @return array{lines: Collection<int, JournalLine>, page: int, pages: int, count: int, debit: int, credit: int, before_debit: int, before_credit: int}
     */
    public static function slice(Builder $lines, ?int $page, ?int $perPage): array
    {
        $totals = (clone $lines)->toBase()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(journal_lines.debit), 0) AS d, COALESCE(SUM(journal_lines.credit), 0) AS c')
            ->first();
        $count = (int) ($totals->n ?? 0);

        $ordered = fn (): Builder => (clone $lines)
            ->orderBy('journal_lines.entry_date')
            ->orderBy('journal_lines.journal_entry_id')
            ->orderBy('journal_lines.line_no');

        if ($perPage === null) {
            $pages = 1;
            $page = 1;
            $offset = 0;
            $pageLines = $ordered()->select('journal_lines.*')->get();
        } else {
            $pages = max(1, (int) ceil($count / $perPage));
            $page = $page === null ? $pages : min(max(1, $page), $pages);
            $offset = ($page - 1) * $perPage;
            $pageLines = $ordered()->select('journal_lines.*')->offset($offset)->limit($perPage)->get();
        }

        $before = (object) ['d' => 0, 'c' => 0];
        if ($offset > 0) {
            $earlier = $ordered()->toBase()->select('journal_lines.debit', 'journal_lines.credit')->limit($offset);
            $before = DB::query()->fromSub($earlier, 't')
                ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')
                ->first() ?? $before;
        }

        return [
            'lines' => $pageLines,
            'page' => $page,
            'pages' => $pages,
            'count' => $count,
            'debit' => (int) ($totals->d ?? 0),
            'credit' => (int) ($totals->c ?? 0),
            'before_debit' => (int) $before->d,
            'before_credit' => (int) $before->c,
        ];
    }
}
