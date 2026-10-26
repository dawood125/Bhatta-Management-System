<?php

namespace App\Backups;

use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;

/**
 * Makes a backup now (the same one as the nightly backup) and returns how it went.
 */
class BackupRunner
{
    public function run(?User $by, string $kind = BackupRun::MANUAL): BackupRun
    {
        $before = (int) BackupRun::query()->max('id');

        Context::add('backup.kind', $kind);
        Context::add('backup.user', $by?->id);

        // Someone is waiting for the result: one try (the nightly backup tries again after a pause).
        if ($by !== null) {
            config(['backup.backup.tries' => 1]);
        }

        try {
            @set_time_limit(900);
            Artisan::call('backup:run', ['--no-interaction' => true]);
        } finally {
            Context::forget(['backup.kind', 'backup.user']);
        }

        // The backup's own rows (a copy to Google Drive is reported on its own).
        $runs = BackupRun::query()->where('id', '>', $before)->where('kind', '!=', BackupRun::COPY)->orderBy('id')->get();

        return $runs->firstWhere('status', BackupRun::FAILED)
            ?? $runs->first()
            ?? BackupRun::query()->create([
                'kind' => $kind,
                'status' => BackupRun::FAILED,
                'user_id' => $by?->id,
                'message' => trim(Artisan::output()) ?: 'The backup did not run.',
            ]);
    }
}
