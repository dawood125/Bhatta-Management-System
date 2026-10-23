<?php

namespace App\Livewire\Labour;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Models\JournalEntry;
use App\Models\Settlement;
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
 * Wage settlements paid in a period, newest first.
 *
 * @property-read LengthAwarePaginator<int, Settlement>|null $settlements Livewire computed property
 * @property-read Money $totalPaid Livewire computed property
 */
class SettlementIndex extends Component
{
    use FiltersByDateRange, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::LabourView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Settlement>|null
     */
    #[Computed]
    public function settlements(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['entry', 'treasuryAccount', 'creator'])
            ->withCount('lines')
            ->orderByDesc('settlement_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** Paid in the period (reversed payments left out). */
    #[Computed]
    public function totalPaid(): Money
    {
        return Money::fromPaisa((int) ($this->filtered()
            ?->whereHas('entry', fn (Builder $q) => $q->where('status', JournalEntry::STATUS_POSTED))
            ->sum('total_paid') ?? 0));
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.labour.settlement-index', [
            'canPay' => $actor->can(Permission::LabourPay->value),
            'range' => $this->dateRange(),
        ])->title(__('Settlements'));
    }

    /**
     * @return Builder<Settlement>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);

        return Settlement::query()
            ->whereBetween('settlement_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('lines.worker', fn (Builder $w) => $w->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%"))));
    }
}
