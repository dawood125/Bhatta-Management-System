<?php

namespace App\Livewire\Materials;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Materials\MaterialStock;
use App\Models\Material;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Material cost report for a period: per material the stock at the start, what was bought (with
 * charges), used and lost, and the stock at the end, in quantity and value. "Used" is what the
 * materials cost the kiln in the period (the owner's "coal cost", "Bora cost").
 *
 * @property-read Collection<int, array{material: Material, opening: array{quantity: Quantity, value: Money}, bought: array{quantity: Quantity, value: Money}, used: array{quantity: Quantity, value: Money}, shortage: array{quantity: Quantity, value: Money}, closing: array{quantity: Quantity, value: Money}}>|null $rows Livewire computed property
 */
class CostReport extends Component
{
    // Not paginated; WithPagination is what the date filter resets on a new range.
    use FiltersByDateRange, WithPagination;

    public function mount(): void
    {
        $this->authorize(Permission::MaterialsView->value);
        $this->initDateRange();
    }

    /**
     * @return Collection<int, array{material: Material, opening: array{quantity: Quantity, value: Money}, bought: array{quantity: Quantity, value: Money}, used: array{quantity: Quantity, value: Money}, shortage: array{quantity: Quantity, value: Money}, closing: array{quantity: Quantity, value: Money}}>|null
     */
    #[Computed]
    public function rows(): ?Collection
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        // Archived materials without any stock or activity in the period are left out.
        return app(MaterialStock::class)->report($range[0], $range[1])->filter(fn (array $row) => $row['material']->is_active
            || ! $row['opening']['quantity']->isZero() || ! $row['bought']['quantity']->isZero()
            || ! $row['used']['quantity']->isZero() || ! $row['shortage']['quantity']->isZero())->values();
    }

    public function render(): View
    {
        $rows = $this->rows;
        $total = fn (string $key) => Money::sum($rows?->map(fn (array $row) => $row[$key]['value'])->all() ?? []);

        return view('livewire.materials.cost-report', [
            'range' => $this->dateRange(),
            'totals' => $rows === null ? null : [
                'opening' => $total('opening'),
                'bought' => $total('bought'),
                'used' => $total('used'),
                'shortage' => $total('shortage'),
                'closing' => $total('closing'),
            ],
        ])->title(__('Material cost report'));
    }
}
