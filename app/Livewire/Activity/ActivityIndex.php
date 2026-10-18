<?php

namespace App\Livewire\Activity;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only audit trail of the current kiln: who changed what, and when.
 */
class ActivityIndex extends Component
{
    use WithPagination;

    #[Url(as: 'user', except: '')]
    public string $causerId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize(Permission::ActivityView->value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['causerId', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('causerId', 'from', 'to');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);

        return Activity::query()
            ->forCurrentKiln()
            ->with(['causer', 'subject'])
            ->when(ctype_digit($this->causerId), fn (Builder $q) => $q
                ->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', (int) $this->causerId))
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn (Builder $q) => $q->where('created_at', '<=', $to->endOfDay()))
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return array<int|string, string>
     */
    #[Computed]
    public function userOptions(): array
    {
        $kilnId = app(CurrentKiln::class)->id();

        return User::query()
            ->whereHas('kilns', fn (Builder $q) => $q->whereKey($kilnId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function render(): View
    {
        return view('livewire.activity.activity-index')->title(__('Activity log'));
    }

    private function parseDate(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
