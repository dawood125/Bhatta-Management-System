<?php

namespace App\Http\Controllers;

use App\Backups\BackupCatalog;
use App\Enums\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download one backup (owner only). Only a file that is in the list of backups can be reached,
 * and every download is in the activity log.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, BackupCatalog $catalog, string $disk, string $file): StreamedResponse
    {
        abort_unless($request->user()?->can(Permission::BackupsManage->value), 403);

        $backup = $catalog->find($disk, $file);
        abort_if($backup === null, 404);

        activity()->causedBy($request->user())->event('backup_downloaded')
            ->withProperties(['file' => $backup['file'], 'disk' => $disk])
            ->log('backup_downloaded');

        return Storage::disk($disk)->download($backup['path'], $catalog->name().'-'.$backup['file'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }
}
