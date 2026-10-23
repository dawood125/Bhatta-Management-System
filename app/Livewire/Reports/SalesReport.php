<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersReportPeriod;
use App\Models\Party;
use App\Models\Product;
use App\Reports\SalesSummary;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Sales of a period by grade, by customer and by sales agent.
 *
 * @property-read array{count: int, bricks: Money, carriage: Money, credits: Money, billed: Money, paid: Money, outstanding: Money, by_grade: list<array{product: Product, ordered: Quantity, delivered: Quantity, amount: Money}>, by_customer: list<array{party: Party, count: int, billed: Money, outstanding: Money}>, by_agent: list<array{agent: Party|null, count: int, billed: Money}>}|null $report Livewire computed property
 */
class SalesReport extends Component
{
    use FiltersReportPeriod;

    public function mount(): void
    {
        $this->authorize(Permission::SalesView->value);
        $this->initDateRange();
    }

    /**
     * @return array{count: int, bricks: Money, carriage: Money, credits: Money, billed: Money, paid: Money, outstanding: Money, by_grade: list<array{product: Product, ordered: Quantity, delivered: Quantity, amount: Money}>, by_customer: list<array{party: Party, count: int, billed: Money, outstanding: Money}>, by_agent: list<array{agent: Party|null, count: int, billed: Money}>}|null
     */
    #[Computed]
    public function report(): ?array
    {
        $range = $this->dateRange();

        return $range === null ? null : app(SalesSummary::class)->forPeriod($range[0], $range[1]);
    }

    public function render(): View
    {
        return view('livewire.reports.sales-report', ['range' => $this->dateRange()])->title(__('Sales summary'));
    }
}
