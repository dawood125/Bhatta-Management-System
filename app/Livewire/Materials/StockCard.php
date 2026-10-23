<?php

namespace App\Livewire\Materials;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\ReversesMaterialEntries;
use App\Livewire\Concerns\RunsActions;
use App\Materials\MaterialStock;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock card of one material: stock before the period, each purchase, use, shortage and
 * reversal with the running quantity and value, and the stock at the end. Printable.
 *
 * @property-read array{quantity: Quantity, value: Money} $current Livewire computed property
 */
class StockCard extends Component
{
    // The card is not paginated; WithPagination is what the date filter resets on a new range.
    use FiltersByDateRange, ReversesMaterialEntries, RunsActions, WithPagination;

    public Material $material;

    public function mount(Material $material): void
    {
        $this->authorize(Permission::MaterialsView->value);
        $this->material = $material;
        $this->initDateRange();
    }

    /**
     * @return array{opening: array{quantity: Quantity, value: Money}, rows: list<array{movement: MaterialMovement, quantity: Quantity, value: Money}>, closing: array{quantity: Quantity, value: Money}}|null
     */
    #[Computed]
    public function card(): ?array
    {
        $range = $this->dateRange();

        return $range === null ? null : app(MaterialStock::class)->card($this->material, $range[0], $range[1]);
    }

    /**
     * @return array{quantity: Quantity, value: Money}
     */
    #[Computed]
    public function current(): array
    {
        return app(MaterialStock::class)->levels([$this->material->id])[$this->material->id];
    }

    protected function afterReverse(): void
    {
        unset($this->card, $this->current);
    }

    public function render(): View
    {
        $this->material->loadMissing('unit');

        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.materials.stock-card', [
            'range' => $this->dateRange(),
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'average' => app(MaterialStock::class)->averageCost($this->current),
        ])->title(__('Stock card').' · '.$this->material->displayName());
    }
}
