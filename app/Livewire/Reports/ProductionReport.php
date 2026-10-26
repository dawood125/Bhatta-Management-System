<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Enums\StageKind;
use App\Livewire\Concerns\FiltersReportPeriod;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Reports\ProductionSummary;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Production of a period: stock by stage and grade, work done, Nakaasi by grade, deliveries by
 * grade and a line per day.
 *
 * @property-read array{slots: list<array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}>, pathai: array{made: Quantity, rejected: Quantity, accepted: Quantity}, loaded: Quantity, fired: Quantity, unloaded: Quantity, unloaded_by_product: array<int, Quantity>, delivered_by_product: array<int, Quantity>, days: list<array{date: string, made: Quantity, accepted: Quantity, loaded: Quantity, fired: Quantity, unloaded: Quantity, delivered: Quantity}>}|null $report Livewire computed property
 * @property-read Collection<int, Product> $products Livewire computed property
 * @property-read Collection<string, ProductionStage> $stages Livewire computed property
 */
class ProductionReport extends Component
{
    use FiltersReportPeriod;

    public function mount(): void
    {
        $this->authorize(Permission::ProductionView->value);
        $this->initDateRange();
    }

    /**
     * @return array{slots: list<array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}>, pathai: array{made: Quantity, rejected: Quantity, accepted: Quantity}, loaded: Quantity, fired: Quantity, unloaded: Quantity, unloaded_by_product: array<int, Quantity>, delivered_by_product: array<int, Quantity>, days: list<array{date: string, made: Quantity, accepted: Quantity, loaded: Quantity, fired: Quantity, unloaded: Quantity, delivered: Quantity}>}|null
     */
    #[Computed]
    public function report(): ?array
    {
        $range = $this->dateRange();

        return $range === null ? null : app(ProductionSummary::class)->forPeriod($range[0], $range[1]);
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()->with('unit')->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * @return Collection<string, ProductionStage>
     */
    #[Computed]
    public function stages(): Collection
    {
        return ProductionStage::query()->orderBy('sort_order')->get()->keyBy(fn (ProductionStage $stage) => $stage->kind->value);
    }

    public function render(): View
    {
        return view('livewire.reports.production-report', ['range' => $this->dateRange()])->title(__('Production summary'));
    }
}
