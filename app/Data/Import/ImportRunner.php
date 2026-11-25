<?php

namespace App\Data\Import;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks and imports the rows of a file, all or nothing.
 *
 * The check really imports every row inside a transaction and then rolls it all back, so it
 * finds exactly the problems the real import would (a duplicate, an unknown category, not
 * enough stock…). The import does the same and keeps the result only when every row worked.
 */
class ImportRunner
{
    /**
     * @param  list<array{line: int, values: array<string, mixed>}>  $rows
     * @return array{rows: list<array{line: int, summary: string, errors: list<string>}>, ok: int, failed: int, saved: bool}
     */
    public function run(User $actor, Importer $importer, array $rows, bool $save): array
    {
        abort_unless($importer->allowed($actor), 403);

        $report = [];
        $failed = 0;
        $batch = (string) Str::uuid();

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $errors = [];
                try {
                    // A savepoint per row: a failed row leaves the others as they were.
                    DB::transaction(fn () => $importer->importRow($actor, $row['values'], $batch.':'.$index));
                } catch (ValidationException $e) {
                    $errors = array_values(array_unique(array_merge(...array_values($e->errors()))));
                } catch (Throwable $e) {
                    report($e);
                    $errors = [__('This row could not be imported.')];
                }

                if ($errors !== []) {
                    $failed++;
                }
                $report[] = ['line' => $row['line'], 'summary' => $importer->describe($row['values']), 'errors' => $errors];
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $saved = $save && $failed === 0 && $rows !== [];
        $saved ? DB::commit() : DB::rollBack();

        if ($saved) {
            activity()
                ->causedBy($actor)
                ->event('data_imported')
                ->withProperties(['attributes' => ['import' => $importer->key(), 'rows' => count($rows)]])
                ->log('data_imported');
        }

        return ['rows' => $report, 'ok' => count($rows) - $failed, 'failed' => $failed, 'saved' => $saved];
    }
}
