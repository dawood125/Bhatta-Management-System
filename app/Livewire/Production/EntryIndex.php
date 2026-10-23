<?php

namespace App\Livewire\Production;

use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\ReversesProductionEntries;
use App\Livewire\Concerns\RunsActions;
use App\Models\ProductionEntry;
use App\Models\ProductionStage;
use App\Production\BrickStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * All production entries for a period, newest first; filter by kind or search, reverse mistakes.
 *
 * @property-read LengthAwarePaginator<int, ProductionEntry>|null $entries Livewire computed property
 */
class EntryIndex extends Component
{
    use FiltersByDateRange, ReversesProductionEntries, RunsActions, WithPagination;

    #[Url(except: '')]
    public string $kind = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::ProductionView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['kind', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, ProductionEntry>|null
     */
    #[Computed]
    public function entries(): ?LengthAwarePaginator
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);

        return ProductionEntry::query()
            ->whereBetween('entry_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when(ProductionEntryKind::tryFrom($this->kind), fn (Builder $q, ProductionEntryKind $kind) => $q->where('kind', $kind))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('team', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")))
            ->with(['movements.product.unit', 'creator', 'reverser', 'materialUsage.movements.material.unit', 'worker', 'workEntry'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Ids of entries on this page that look entered twice.
     *
     * @return list<int>
     */
    #[Computed]
    public function duplicates(): array
    {
        return $this->entries === null ? [] : app(BrickStock::class)->possibleDuplicates(collect($this->entries->items()));
    }

    /**
     * @return Collection<string, ProductionStage>
     */
    #[Computed]
    public function stages(): Collection
    {
        return ProductionStage::query()->orderBy('sort_order')->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);
    }

    /**
     * @return array<string, string>
     */
    public function kindOptions(): array
    {
        $options = ['' => __('All entries')];
        foreach (ProductionEntryKind::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    #[On('production-recorded')]
    public function refresh(): void
    {
        unset($this->entries, $this->duplicates);
    }

    protected function afterReverse(): void
    {
        unset($this->entries, $this->duplicates);
    }

    public function render(): View
    {
        return view('livewire.production.entry-index', ['range' => $this->dateRange()])->title(__('Production entries'));
    }
}
