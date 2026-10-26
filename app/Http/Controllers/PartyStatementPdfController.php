<?php

namespace App\Http\Controllers;

use App\Accounting\LedgerQueries;
use App\Enums\Permission;
use App\Models\Party;
use App\Support\Kiln\CurrentKiln;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A party's statement (khata) for a period as a PDF in the viewer's language (D-044: the
 * server-side, Urdu-capable PDF that comes with the invoice PDFs). ?from=YYYY-MM-DD&to=YYYY-MM-DD;
 * the period defaults to the party's opening date until today.
 */
class PartyStatementPdfController extends Controller
{
    public function __invoke(Request $request, Party $party, LedgerQueries $ledger, PdfRenderer $renderer, CurrentKiln $currentKiln): Response
    {
        abort_unless($request->user()?->can(Permission::PartiesView->value), 403);

        $from = $this->date($request->query('from')) ?? $party->opening_date->copy();
        $to = $this->date($request->query('to')) ?? now()->startOfDay();
        abort_if($from->gt($to), 422);

        $kiln = $currentKiln->get();
        $statement = $ledger->partyStatement($party, $from, $to);

        $pdf = $renderer->render('pdf.statement', [
            'kiln' => $kiln,
            'party' => $party,
            'from' => $from,
            'to' => $to,
            'statement' => $statement,
        ], __('Statement of :name', ['name' => $party->displayName()]), $kiln->displayName());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($party->name ?: 'party').'-statement.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
