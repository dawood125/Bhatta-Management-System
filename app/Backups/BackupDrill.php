<?php

namespace App\Backups;

use App\Accounting\LedgerVerifier;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The restore drill: proves a backup can really be put back. It makes a fresh backup, restores it
 * into a separate empty database, checks that every table came back with the same rows (row count
 * and checksum) and that the restored books are consistent, then empties that database again.
 */
class BackupDrill
{
    /** Tables that change all the time and are not part of the books. */
    public const VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'backup_runs'];

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupRunner $runner,
        private readonly BackupRestorer $restorer,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('backup.drill_database'));
    }

    public function run(?User $by = null, bool $fresh = true): BackupRun
    {
        $drillDatabase = (string) config('backup.drill_database');
        $liveDatabase = DB::connection()->getDatabaseName();
        $details = ['database' => $drillDatabase];

        try {
            if ($drillDatabase === '') {
                throw new RuntimeException('No drill database is set (BACKUP_DRILL_DATABASE).');
            }
            if ($drillDatabase === $liveDatabase) {
                throw new RuntimeException('The drill database must be a separate database, not the live one.');
            }

            if ($fresh) {
                $made = $this->runner->run($by, $by ? BackupRun::MANUAL : BackupRun::SCHEDULED);
                if (! $made->succeeded()) {
                    throw new RuntimeException('A fresh backup could not be made: '.$made->message);
                }
            }

            $backup = $this->catalog->newest($this->catalog->disks()[0] ?? null)
                ?? throw new RuntimeException('There is no backup to restore.');
            $details['backup'] = $backup['file'];

            try {
                $this->restorer->restore($backup, $drillDatabase);
                $differences = $this->compare($liveDatabase, $drillDatabase);
                $problems = $this->verifyBooks($drillDatabase);
                $details += [
                    'tables' => $differences['tables'],
                    'rows' => $differences['rows'],
                    'differences' => $differences['differences'],
                    'problems' => array_slice($problems, 0, 20),
                ];
            } finally {
                $this->restorer->emptyDatabase($drillDatabase);
            }

            $ok = $details['differences'] === [] && $details['problems'] === [];

            return $this->record($by, $ok ? BackupRun::SUCCEEDED : BackupRun::FAILED, $details, $ok ? null : Str::limit(
                implode(' ', array_merge($details['differences'], $details['problems'])), 1000,
            ));
        } catch (Throwable $e) {
            return $this->record($by, BackupRun::FAILED, $details, Str::limit($e->getMessage(), 1000));
        }
    }

    /**
     * Every table of the live database must be in the copy with the same rows.
     *
     * @return array{tables: int, rows: int, differences: list<string>}
     */
    public function compare(string $liveDatabase, string $copyDatabase): array
    {
        $live = array_values(array_diff($this->restorer->tables($liveDatabase), self::VOLATILE));
        $copy = $this->restorer->tables($copyDatabase);
        $differences = [];
        $rows = 0;

        // A connection of its own sees what is saved in the live database (as the backup does),
        // never the half of something that is being saved right now.
        $connection = $this->restorer->connection($liveDatabase, 'backup_compare');

        foreach ($live as $table) {
            if (! in_array($table, $copy, true)) {
                $differences[] = "Table {$table} is missing from the copy.";

                continue;
            }

            $liveCount = $this->count($connection, $liveDatabase, $table);
            $copyCount = $this->count($connection, $copyDatabase, $table);
            $rows += $copyCount;

            if ($liveCount !== $copyCount) {
                $differences[] = "Table {$table}: {$liveCount} rows live, {$copyCount} in the copy.";
            } elseif ($this->checksum($connection, $liveDatabase, $table) !== $this->checksum($connection, $copyDatabase, $table)) {
                $differences[] = "Table {$table}: the rows differ from the live ones.";
            }
        }

        return ['tables' => count($live), 'rows' => $rows, 'differences' => $differences];
    }

    /** @return list<string> */
    private function verifyBooks(string $database): array
    {
        $this->restorer->connection($database);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(BackupRestorer::TARGET_CONNECTION);

        try {
            return app(LedgerVerifier::class)->verify();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function count(Connection $connection, string $database, string $table): int
    {
        return (int) $connection->selectOne('SELECT COUNT(*) AS n FROM '.$this->name($database, $table))->n;
    }

    private function checksum(Connection $connection, string $database, string $table): string
    {
        $row = (array) $connection->selectOne('CHECKSUM TABLE '.$this->name($database, $table));

        return (string) ($row['Checksum'] ?? '');
    }

    private function name(string $database, string $table): string
    {
        return '`'.str_replace('`', '``', $database).'`.`'.str_replace('`', '``', $table).'`';
    }

    /** @param array<string, mixed> $details */
    private function record(?User $by, string $status, array $details, ?string $message): BackupRun
    {
        return BackupRun::query()->create([
            'kind' => BackupRun::DRILL,
            'status' => $status,
            'disk' => $this->catalog->disks()[0] ?? null,
            'path' => isset($details['backup']) ? $this->catalog->name().'/'.$details['backup'] : null,
            'user_id' => $by?->id,
            'details' => $details,
            'message' => $message,
        ]);
    }
}
