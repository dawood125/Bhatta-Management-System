<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Sale;
use App\Sales\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A sale's invoice as a PDF in the viewer's language: shown in the browser (to print or save),
 * or downloaded with ?download=1.
 */
class SaleInvoiceController extends Controller
{
    public function __invoke(Request $request, Sale $sale, InvoicePdf $invoicePdf): Response
    {
        abort_unless($request->user()?->can(Permission::SalesView->value), 403);

        return response($invoicePdf->render($sale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$sale->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
