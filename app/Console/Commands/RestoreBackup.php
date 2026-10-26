<?php

namespace App\Console\Commands;

use App\Accounting\LedgerVerifier;
use App\Backups\BackupCatalog;
use App\Backups\BackupRestorer;
use App\Backups\BackupRunner;
use App\Models\BackupRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Laravel\Prompts\text;

#[Signature('kiln:restore-backup
    {file? : The backup file, e.g. 2026-10-24-02-00-00.zip (default: the newest)}
    {--disk= : The backup disk (default: the first in BACKUP_DISKS)}
    {--into= : Restore into this other, empty database instead of the live one}
    {--without-files : Leave the uploaded files (kiln logo) as they are}
    {--force : Do not ask for confirmation}')]
#[Description('Put a backup back: the database and the uploaded files (see docs/07-deployment.md)')]
class RestoreBackup extends Command
{
    public function handle(BackupCatalog $catalog, BackupRestorer $restorer, BackupRunner $runner, LedgerVerifier $verifier): int
    {
        $disk = (string) ($this->option('disk') ?: ($catalog->disks()[0] ?? ''));
        $file = $this->argument('file');
        $backup = is_string($file) ? $catalog->find($disk, $file) : $catalog->newest($disk);

        if ($backup === null) {
            $this->components->error(is_string($file) ? "There is no backup {$file} on the disk {$disk}." : "There are no backups on the disk {$disk}.");
            $this->listBackups($catalog, $disk);

            return self::FAILURE;
        }

        $live = DB::connection()->getDatabaseName();
        $into = (string) $this->option('into');

        if ($into !== '' && $into !== $live) {
            $result = $restorer->restore($backup, $into);
            $this->components->info("Restored {$backup['file']} into the database {$into} ({$result['tables']} tables). The live database was not touched.");

            return self::SUCCESS;
        }

        $this->components->warn("This replaces everything in the live database {$live} with the backup of {$backup['date']->format('d-m-Y H:i')}. Anything entered after that time will be lost.");
        if (! $this->option('force') && text("Type the database name ({$live}) to go ahead") !== $live) {
            $this->components->info('Nothing was changed.');

            return self::FAILURE;
        }

        $this->components->info('Backing up the database as it is now…');
        $safety = $runner->run(null, BackupRun::MANUAL);
        if (! $safety->succeeded()) {
            $this->components->error('The safety backup failed, so nothing was restored: '.$safety->message);

            return self::FAILURE;
        }

        Artisan::call('down', ['--retry' => 60]);

        try {
            $this->components->task("Restoring {$backup['file']}", fn () => (bool) $restorer->restore($backup, $live, ! $this->option('without-files'), intoLiveDatabase: true));
            $this->components->task('Bringing the database up to this version', fn () => Artisan::call('migrate', ['--force' => true]) === 0);
            Artisan::call('optimize:clear');
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $this->components->error('The site stays in maintenance mode. The database as it was before is in the newest backup '
                .'('.basename((string) $safety->path).'). Fix the problem and run this command again, or restore that backup.');

            return self::FAILURE;
        }

        $problems = $verifier->verify();
        Artisan::call('up');

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }
            $this->components->warn('Restored, but the books in the backup have problems (listed above).');

            return self::FAILURE;
        }

        $this->components->info("Restored {$backup['file']}. The books are consistent and the site is open again.");

        return self::SUCCESS;
    }

    private function listBackups(BackupCatalog $catalog, string $disk): void
    {
        $backups = $catalog->backups()->where('disk', $disk)->take(10);
        if ($backups->isNotEmpty()) {
            $this->table(['Backup', 'Made', 'Size'], $backups->map(fn (array $b) => [$b['file'], $b['date']->format('d-m-Y H:i'), number_format($b['size'] / 1024, 0).' KB'])->all());
        }
    }
}
