<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Reports\FinancialStatements;
use App\Support\Kiln\CurrentKiln;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Profit and loss (?from=&to=) and the balance sheet (?date=) as PDFs in the viewer's language.
 */
class ReportPdfController extends Controller
{
    public function __invoke(Request $request, string $report, FinancialStatements $statements, PdfRenderer $renderer, CurrentKiln $currentKiln): Response
    {
        abort_unless($request->user()?->can(Permission::FinancialReportsView->value), 403);
        $kiln = $currentKiln->get();

        if ($report === 'profit-and-loss') {
            $from = $this->date($request->query('from')) ?? now()->startOfMonth();
            $to = $this->date($request->query('to')) ?? now()->startOfDay();
            abort_if($from->gt($to), 422);

            $title = __('Profit and loss');
            $pdf = $renderer->render('pdf.profit-and-loss', [
                'kiln' => $kiln, 'from' => $from, 'to' => $to, 'report' => $statements->profitAndLoss($from, $to),
            ], $title, $kiln->displayName());
            $name = 'profit-and-loss-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.pdf';
        } else {
            $date = $this->date($request->query('date')) ?? now()->startOfDay();
            $title = __('Balance sheet');
            $pdf = $renderer->render('pdf.balance-sheet', [
                'kiln' => $kiln, 'date' => $date, 'report' => $statements->balanceSheet($date),
            ], $title, $kiln->displayName());
            $name = 'balance-sheet-'.$date->format('Y-m-d').'.pdf';
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value && $date->lte(now()) ? $date->startOfDay() : null;
    }
}
