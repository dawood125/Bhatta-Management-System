<?php

namespace App\Jobs;

use App\Backups\BackupCatalog;
use App\Backups\GoogleDrive;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies one backup to the owner's Google Drive after it is made. Tried three times (now, after
 * 5 minutes, after 30), then recorded as failed on the Backups page.
 */
class CopyBackupToGoogleDrive implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    public function __construct(
        public readonly string $disk,
        public readonly string $file,
        public readonly ?int $userId = null,
    ) {}

    public function handle(BackupCatalog $catalog, GoogleDrive $drive): void
    {
        $backup = $catalog->find($this->disk, $this->file)
            ?? throw new RuntimeException("The backup {$this->file} is no longer there.");

        $drive->copy($backup);

        BackupRun::query()->create([
            'kind' => BackupRun::COPY,
            'status' => BackupRun::SUCCEEDED,
            'disk' => 'google_drive',
            'path' => $backup['file'],
            'size_bytes' => $backup['size'],
            'user_id' => $this->userId !== null && User::query()->whereKey($this->userId)->exists() ? $this->userId : null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        BackupRun::query()->create([
            'kind' => BackupRun::COPY,
            'status' => BackupRun::FAILED,
            'disk' => 'google_drive',
            'path' => $this->file,
            'message' => Str::limit((string) $e?->getMessage(), 1000),
            'user_id' => $this->userId,
        ]);
    }
}
