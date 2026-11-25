<?php

namespace App\Http\Controllers\Data;

use App\Data\Excel;
use App\Data\Import\ImportCatalog;
use App\Enums\Permission;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Downloads the Excel template of an import, with example rows and a Help sheet. */
class ImportTemplateController
{
    public function __invoke(string $type, ImportCatalog $catalog): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(Permission::DataManage->value), 403);
        $importer = $catalog->find($type);
        abort_if($importer === null, 404);

        $path = Excel::tempPath();
        $catalog->writeTemplate($importer, $path);

        return response()->download($path, 'bhatta-'.Str::slug($importer->key()).'-template.xlsx')->deleteFileAfterSend();
    }
}
