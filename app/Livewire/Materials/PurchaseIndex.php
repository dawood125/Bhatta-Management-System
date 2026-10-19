<?php

namespace App\Livewire\Materials;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\ReversesMaterialEntries;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Material purchases for a period, with totals; filter by material or supplier, then print.
 *
 * @property-read LengthAwarePaginator<int, MaterialPurchase>|null $purchases Livewire computed property
 * @property-read array{goods: Money, charges: Money, total: Money, count: int} $totals Livewire computed property
 */
class PurchaseIndex extends Component
{
    use FiltersByDateRange, ReversesMaterialEntries, RunsActions, WithPagination;

    #[Url(except: '')]
    public string $material = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::MaterialsView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['material', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, MaterialPurchase>|null
     */
    #[Computed]
    public function purchases(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['entry', 'supplier', 'treasuryAccount', 'lines.material.unit', 'creator'])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Totals of the filtered purchases that still count (reversed ones are left out).
     *
     * @return array{goods: Money, charges: Money, total: Money, count: int}
     */
    #[Computed]
    public function totals(): array
    {
        $row = $this->filtered()
            ?->whereHas('entry', fn (Builder $q) => $q->where('status', JournalEntry::STATUS_POSTED))
            ->toBase()
            ->selectRaw('COALESCE(SUM(goods_total), 0) AS goods, COALESCE(SUM(charges_total), 0) AS charges, COALESCE(SUM(total), 0) AS total, COUNT(*) AS n')
            ->first();

        return [
            'goods' => Money::fromPaisa((int) ($row->goods ?? 0)),
            'charges' => Money::fromPaisa((int) ($row->charges ?? 0)),
            'total' => Money::fromPaisa((int) ($row->total ?? 0)),
            'count' => (int) ($row->n ?? 0),
        ];
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function materialOptions(): array
    {
        return Material::query()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (Material $m) => [$m->id => $m->displayName()])->all();
    }

    protected function afterReverse(): void
    {
        unset($this->purchases, $this->totals);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.materials.purchase-index', [
            'canRecord' => $actor->can(Permission::MaterialsRecord->value),
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'range' => $this->dateRange(),
        ])->title(__('Material purchases'));
    }

    /**
     * @return Builder<MaterialPurchase>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);

        return MaterialPurchase::query()
            ->whereBetween('purchase_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when(ctype_digit($this->material), fn (Builder $q) => $q->whereHas('lines', fn (Builder $l) => $l->where('material_id', (int) $this->material)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('entry', fn (Builder $e) => $e->where('number', 'like', "%{$search}%"))
                ->orWhereHas('supplier', fn (Builder $p) => $p->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%"))));
    }
}
