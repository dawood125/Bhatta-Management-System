<?php

namespace App\Sales;

use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * A sale's invoice as a PDF, in the current language: the kiln's name, address, phone and logo,
 * the customer, each grade with quantity, rate and amount, deliveries so far, carriage, payments
 * and what is still owed (proposed default for Q-33).
 */
class InvoicePdf
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly CurrentKiln $currentKiln,
        private readonly SaleStock $saleStock,
        private readonly CustomerPayments $payments,
    ) {}

    public function render(Sale $sale): string
    {
        $kiln = $this->currentKiln->get();
        $sale->loadMissing(['customer', 'agent', 'lines.product.unit', 'dispatches.driver', 'dispatches.lines', 'creditEntry', 'receiptEntry']);

        $dispatches = $sale->dispatches->reject(fn (SaleDispatch $dispatch) => $dispatch->isReversed())->sortBy([['dispatch_date', 'asc'], ['id', 'asc']])->values();
        $logo = $kiln->logo_path !== null && Storage::disk('public')->exists($kiln->logo_path) ? Storage::disk('public')->path($kiln->logo_path) : null;

        return $this->renderer->render('pdf.invoice', [
            'kiln' => $kiln,
            'logo' => $logo,
            'sale' => $sale,
            'delivered' => $this->saleStock->delivered($sale->lines->pluck('id')->all()),
            'dispatches' => $dispatches,
            'carriage' => Money::sum($dispatches->map(fn (SaleDispatch $dispatch) => $dispatch->carriage)),
            'credit' => $sale->creditEntry->total ?? Money::zero(),
            'payment' => $this->payments->forSale($sale),
            'balance' => $sale->customer->balance(),
        ], __('Invoice :number', ['number' => $sale->number]), $kiln->displayName());
    }
}
