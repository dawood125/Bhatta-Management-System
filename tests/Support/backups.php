<?php

use App\Backups\BackupRestorer;
use Illuminate\Support\Facades\Storage;

/** Put a (fake) backup zip on the backups disk, named for its date like a real one. */
function fakeBackup(string $date, string $content = 'PK fake zip'): string
{
    $file = $date.'.zip';
    Storage::disk('backups')->put(config('backup.backup.name').'/'.$file, $content);

    return $file;
}

/** Whether the separate database for restore tests (gbricks_restore) exists and can be used. */
function drillDatabaseAvailable(): bool
{
    try {
        app(BackupRestorer::class)->connection('gbricks_restore', 'drill_probe')->select('SELECT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}
