<?php

namespace App\Livewire\Backups;

use App\Backups\BackupCatalog;
use App\Backups\BackupDrill;
use App\Backups\BackupRunner;
use App\Backups\GoogleDrive;
use App\Enums\Permission;
use App\Jobs\CopyBackupToGoogleDrive;
use App\Livewire\Concerns\RunsActions;
use App\Models\AppSetting;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Backups (owner only): whether the nightly backup is healthy, every backup kept with a download
 * link, "Back up now", the restore drill, and the history of both.
 *
 * @property-read \Illuminate\Support\Collection<int, array{disk: string, path: string, file: string, date: Carbon, size: int}> $backups Livewire computed property
 * @property-read Collection<int, BackupRun> $runs Livewire computed property
 */
class BackupIndex extends Component
{
    use RunsActions;

    /** @var array{user_code: string, url: string, expires_at: int}|null While connecting Google Drive: the code to enter and where */
    #[Locked]
    public ?array $driveCode = null;

    public function mount(): void
    {
        $this->authorize(Permission::BackupsManage->value);
    }

    public function backUpNow(BackupRunner $runner): void
    {
        $this->authorize(Permission::BackupsManage->value);

        $run = $runner->run($this->actor());
        activity()->causedBy($this->actor())->event('backup_made')
            ->withProperties(['status' => $run->status, 'file' => $run->path ? basename($run->path) : null])
            ->log('backup_made');

        $run->succeeded()
            ? $this->toast(__('Backup made.'))
            : $this->toast(__('The backup failed: :error', ['error' => (string) $run->message]), 'error');

        unset($this->backups, $this->runs);
    }

    public function testRestore(BackupDrill $drill): void
    {
        $this->authorize(Permission::BackupsManage->value);

        if (! $drill->isConfigured()) {
            $this->toast(__('No test database is set up for the restore drill.'), 'error');

            return;
        }

        $run = $drill->run($this->actor());
        activity()->causedBy($this->actor())->event('restore_tested')
            ->withProperties(['status' => $run->status, 'file' => $run->details['backup'] ?? null])
            ->log('restore_tested');

        $run->succeeded()
            ? $this->toast(__('Restore test passed: the backup came back complete and the books in it are consistent.'))
            : $this->toast(__('Restore test failed: :error', ['error' => (string) $run->message]), 'error');

        unset($this->backups, $this->runs);
    }

    /** Google Drive: start connecting, and show the code to enter at google.com/device. */
    public function connectGoogleDrive(GoogleDrive $drive): void
    {
        $this->authorize(Permission::BackupsManage->value);

        try {
            $this->driveCode = $drive->startConnecting($this->actor());
        } catch (Throwable $e) {
            report($e);
            $this->toast(__('Google Drive could not be reached: :error', ['error' => Str::limit($e->getMessage(), 200)]), 'error');
        }
    }

    /** Polled while the code is shown: has the owner allowed access yet? */
    public function checkGoogleDrive(GoogleDrive $drive): void
    {
        $this->authorize(Permission::BackupsManage->value);
        if ($this->driveCode === null) {
            return;
        }

        try {
            $result = $drive->checkConnecting($this->actor());
        } catch (Throwable $e) {
            report($e);
            $this->driveCode = null;
            $this->toast(__('Google Drive could not be connected: :error', ['error' => Str::limit($e->getMessage(), 200)]), 'error');

            return;
        }

        if ($result === 'waiting') {
            return;
        }

        $this->driveCode = null;
        if ($result === 'connected') {
            activity()->causedBy($this->actor())->event('google_drive_connected')->log('google_drive_connected');
            $this->toast(__('Google Drive is connected. Every backup will now be copied there.'));
        } else {
            $this->toast($result === 'denied' ? __('Access to Google Drive was not allowed.') : __('The code has expired. Press “Connect Google Drive” again.'), 'error');
        }
    }

    public function cancelGoogleDrive(GoogleDrive $drive): void
    {
        $this->authorize(Permission::BackupsManage->value);
        $drive->cancelConnecting($this->actor());
        $this->driveCode = null;
    }

    /** Copy the newest backup to Google Drive now (otherwise each new backup is copied by itself). */
    public function copyNewestNow(BackupCatalog $catalog): void
    {
        $this->authorize(Permission::BackupsManage->value);
        $newest = $catalog->newest($catalog->disks()[0] ?? null);
        if ($newest === null || ! AppSetting::current()->hasGoogleDrive()) {
            return;
        }

        try {
            CopyBackupToGoogleDrive::dispatch($newest['disk'], $newest['file'], $this->actor()->id);
            $this->toast(__('The newest backup is being copied to Google Drive. The result appears below in a minute.'));
        } catch (Throwable $e) {
            // Only without a queue worker (jobs run at once): the job has recorded the failure.
            report($e);
            $this->toast(__('The copy to Google Drive failed: :error', ['error' => Str::limit($e->getMessage(), 200)]), 'error');
        }
        unset($this->runs);
    }

    public function disconnectGoogleDrive(GoogleDrive $drive): void
    {
        $this->authorize(Permission::BackupsManage->value);
        $drive->disconnect();
        activity()->causedBy($this->actor())->event('google_drive_disconnected')->log('google_drive_disconnected');
        $this->toast(__('Google Drive is disconnected. The copies already there stay in your Drive.'));
    }

    /** @return \Illuminate\Support\Collection<int, array{disk: string, path: string, file: string, date: Carbon, size: int}> */
    #[Computed]
    public function backups(): \Illuminate\Support\Collection
    {
        return app(BackupCatalog::class)->backups();
    }

    /** @return Collection<int, BackupRun> */
    #[Computed]
    public function runs(): Collection
    {
        return BackupRun::query()->with('user')->latest('id')->limit(15)->get();
    }

    public function render(BackupCatalog $catalog, BackupDrill $drill): View
    {
        return view('livewire.backups.backup-index', [
            'catalog' => $catalog,
            'healthy' => $catalog->isHealthy(),
            'newest' => $this->backups->first(),
            'unreachable' => $catalog->unreachableDisks(),
            'drillConfigured' => $drill->isConfigured(),
            'lastDrill' => BackupRun::query()->where('kind', BackupRun::DRILL)->latest('id')->first(),
            'driveAvailable' => app(GoogleDrive::class)->isAvailable(),
            'drive' => AppSetting::current(),
            'lastCopy' => BackupRun::query()->where('kind', BackupRun::COPY)->latest('id')->first(),
        ])->title(__('Backups'));
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
