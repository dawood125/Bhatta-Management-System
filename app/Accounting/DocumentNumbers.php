<?php

namespace App\Accounting;

use App\Support\Kiln\CurrentKiln;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per kiln, prefix and year, e.g. TR-2026-0001.
 *
 * Must be called inside the same database transaction that saves the document: the counter row
 * is locked until that transaction ends, and if the save fails the number is rolled back too,
 * so numbers never skip and never repeat.
 */
class DocumentNumbers
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    public function next(string $prefix, CarbonInterface $date): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LedgerIntegrityException('Document numbers must be taken inside a database transaction.');
        }

        $kilnId = $this->currentKiln->id();
        $year = (int) $date->format('Y');
        $now = now();

        DB::table('number_sequences')->insertOrIgnore([
            'kiln_id' => $kilnId,
            'prefix' => $prefix,
            'year' => $year,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = DB::table('number_sequences')
            ->where('kiln_id', $kilnId)
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new LedgerIntegrityException('Number sequence row is missing.');
        }

        $next = (int) $row->last_number + 1;

        DB::table('number_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => $now]);

        return sprintf('%s-%d-%04d', $prefix, $year, $next);
    }
}
