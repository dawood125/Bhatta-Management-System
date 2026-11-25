<?php

namespace App\Http\Controllers\Data;

use App\Data\Excel;
use App\Data\Export\DataExporter;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads a list, or all of them, as an Excel file. Sales, purchases and transactions take
 * an optional date range (?from=…&to=…), as the list pages pass their own.
 */
class ExportController
{
    public function __invoke(Request $request, string $type, DataExporter $exporter, CurrentKiln $kiln): BinaryFileResponse
    {
        abort_unless($type === 'all' || array_key_exists($type, $exporter->lists()), 404);
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($exporter->allowed($actor, $type), 403);

        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? Carbon::createFromFormat('Y-m-d', (string) $request->query($key))?->startOfDay() : null;

        $path = Excel::tempPath();
        Excel::write($path, $exporter->sheets($type, $date('from'), $date('to')));

        activity()->causedBy($actor)->event('data_exported')->withProperties(['attributes' => ['export' => $type]])->log('data_exported');

        $name = Str::slug($kiln->get()->name).'-'.($type === 'all' ? 'all-data' : $type).'-'.now()->format('Y-m-d').'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
