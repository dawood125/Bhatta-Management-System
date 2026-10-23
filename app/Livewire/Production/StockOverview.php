<?php

namespace App\Livewire\Production;

use App\Enums\Permission;
use App\Enums\ProductKind;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Production\BrickStock;
use App\Sales\SaleStock;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Brick stock now: how many bricks are raw, in the kiln, fired and awaiting Nakaasi, and ready
 * for sale by grade (with what open sales still have to collect). The production dialogs open
 * from here.
 *
 * @property-read array{raw: Quantity, in_firing: Quantity, fired_awaiting_unloading: Quantity, saleable: array<int, Quantity>} $levels Livewire computed property
 * @property-read array<int, int> $reserved Livewire computed property
 */
class StockOverview extends Component
{
    public function mount(): void
    {
        $this->authorize(Permission::ProductionView->value);
    }

    /**
     * @return array{raw: Quantity, in_firing: Quantity, fired_awaiting_unloading: Quantity, saleable: array<int, Quantity>}
     */
    #[Computed]
    public function levels(): array
    {
        return app(BrickStock::class)->levels();
    }

    /**
     * Bricks open sales still have to collect, per product (thousandths).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function reserved(): array
    {
        return app(SaleStock::class)->reserved();
    }

    /**
     * Grades and by-products: the active ones, plus any archived one that still has stock.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        $withStock = array_keys(array_filter($this->levels['saleable'], fn (Quantity $q) => ! $q->isZero()));

        return Product::query()
            ->with('unit')
            ->whereIn('kind', [ProductKind::FiredGrade, ProductKind::ByProduct])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $withStock))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<string, ProductionStage>
     */
    #[Computed]
    public function stages(): Collection
    {
        return ProductionStage::query()->orderBy('sort_order')->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);
    }

    #[On('production-recorded')]
    public function refresh(): void
    {
        unset($this->levels, $this->products, $this->reserved);
    }

    public function render(): View
    {
        return view('livewire.production.stock-overview')->title(__('Brick stock'));
    }
}
