<?php

namespace App\Backups;

use App\Jobs\CopyBackupToGoogleDrive;
use App\Models\AppSetting;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Throwable;

/**
 * Writes a backup_runs row for every backup, nightly or started by the owner (who started it is
 * passed through the request context by BackupRunner).
 */
class RecordBackupRun
{
    public function __construct(private readonly BackupCatalog $catalog) {}

    public function succeeded(BackupWasSuccessful $event): void
    {
        $newest = $this->catalog->newest($event->diskName);

        $this->record(BackupRun::SUCCEEDED, $event->diskName, [
            'path' => $newest['path'] ?? null,
            'size_bytes' => $newest['size'] ?? null,
        ]);

        // A copy goes to Google Drive (from the first disk only, so each backup is copied once).
        if ($newest !== null && $event->diskName === ($this->catalog->disks()[0] ?? null) && $this->hasGoogleDrive()) {
            try {
                CopyBackupToGoogleDrive::dispatch($event->diskName, $newest['file'], Context::get('backup.user'));
            } catch (Throwable $e) {
                // Only without a queue worker (jobs run at once): the job has recorded the failed copy,
                // and the backup itself is fine.
                report($e);
            }
        }
    }

    private function hasGoogleDrive(): bool
    {
        try {
            return app(GoogleDrive::class)->isAvailable() && AppSetting::current()->hasGoogleDrive();
        } catch (Throwable) {
            return false;
        }
    }

    public function failed(BackupHasFailed $event): void
    {
        $this->record(BackupRun::FAILED, $event->diskName, [
            'message' => Str::limit($event->exception->getMessage(), 1000),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function record(string $status, ?string $disk, array $attributes): void
    {
        try {
            BackupRun::query()->create([
                'kind' => Context::get('backup.kind', BackupRun::SCHEDULED),
                'status' => $status,
                'disk' => $disk,
                'user_id' => Context::get('backup.user'),
                ...$attributes,
            ]);
        } catch (Throwable $e) {
            // The database itself may be the problem; the backup's own log and email still report it.
            report($e);
        }
    }
}
