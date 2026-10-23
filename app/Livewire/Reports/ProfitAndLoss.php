<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersReportPeriod;
use App\Models\LedgerAccount;
use App\Reports\FinancialStatements;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Profit and loss of a period: income, the costs by group, and the profit. Printable and as PDF.
 *
 * @property-read array{income: list<array{account: LedgerAccount, amount: Money}>, total_income: Money, groups: list<array{key: string, label: string, rows: list<array{account: LedgerAccount, amount: Money}>, total: Money}>, total_expenses: Money, net: Money}|null $report Livewire computed property
 */
class ProfitAndLoss extends Component
{
    use FiltersReportPeriod;

    public function mount(): void
    {
        $this->authorize(Permission::FinancialReportsView->value);
        $this->initDateRange();
    }

    /**
     * @return array{income: list<array{account: LedgerAccount, amount: Money}>, total_income: Money, groups: list<array{key: string, label: string, rows: list<array{account: LedgerAccount, amount: Money}>, total: Money}>, total_expenses: Money, net: Money}|null
     */
    #[Computed]
    public function report(): ?array
    {
        $range = $this->dateRange();

        return $range === null ? null : app(FinancialStatements::class)->profitAndLoss($range[0], $range[1]);
    }

    public function render(): View
    {
        $range = $this->dateRange();

        return view('livewire.reports.profit-and-loss', [
            'range' => $range,
            'pdfUrl' => $range === null ? null : route('reports.pdf', ['report' => 'profit-and-loss', 'from' => $range[0]->format('Y-m-d'), 'to' => $range[1]->format('Y-m-d')]),
        ])->title(__('Profit and loss'));
    }
}
