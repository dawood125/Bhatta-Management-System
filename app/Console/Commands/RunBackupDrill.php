<?php

namespace App\Console\Commands;

use App\Backups\BackupDrill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kiln:backup-drill {--latest : Restore the newest backup instead of making a fresh one}')]
#[Description('Restore drill: restore a backup into BACKUP_DRILL_DATABASE and check it matches the live database')]
class RunBackupDrill extends Command
{
    public function handle(BackupDrill $drill): int
    {
        if (! $drill->isConfigured()) {
            $this->components->warn('No drill database is set (BACKUP_DRILL_DATABASE), so there is nothing to do.');

            return self::SUCCESS;
        }

        $run = $drill->run(null, fresh: ! $this->option('latest'));
        $details = $run->details ?? [];

        if ($run->succeeded()) {
            $this->components->info(sprintf(
                'Restore drill passed: %s restored into %s; %d tables and %s rows match the live database, and the restored books are consistent.',
                $details['backup'] ?? '?', $details['database'] ?? '?', $details['tables'] ?? 0, number_format((int) ($details['rows'] ?? 0)),
            ));

            return self::SUCCESS;
        }

        $this->components->error('Restore drill failed: '.$run->message);

        return self::FAILURE;
    }
}
