<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersReportPeriod;
use App\Reports\CostPerThousand;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * What 1,000 bricks cost and sold for in a period (materials and fuel, labour, running expenses).
 *
 * @property-read array{unloaded: Quantity, delivered: Quantity, costs: list<array{key: string, label: string, amount: Money, per_thousand: Money|null}>, total: Money, total_per_thousand: Money|null, sales: Money, sales_per_thousand: Money|null, margin_per_thousand: Money|null}|null $report Livewire computed property
 */
class CostPerThousandReport extends Component
{
    use FiltersReportPeriod;

    public function mount(): void
    {
        $this->authorize(Permission::FinancialReportsView->value);
        $this->initDateRange();
    }

    /**
     * @return array{unloaded: Quantity, delivered: Quantity, costs: list<array{key: string, label: string, amount: Money, per_thousand: Money|null}>, total: Money, total_per_thousand: Money|null, sales: Money, sales_per_thousand: Money|null, margin_per_thousand: Money|null}|null
     */
    #[Computed]
    public function report(): ?array
    {
        $range = $this->dateRange();

        return $range === null ? null : app(CostPerThousand::class)->forPeriod($range[0], $range[1]);
    }

    public function render(): View
    {
        return view('livewire.reports.cost-report', ['range' => $this->dateRange()])->title(__('Cost per 1,000 bricks'));
    }
}
