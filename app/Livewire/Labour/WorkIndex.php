<?php

namespace App\Livewire\Labour;

use App\Actions\Labour\RecordWorkEntry;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalEntry;
use App\Models\LabourCategory;
use App\Models\LabourRate;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Wages earned in a period (piece work, days, salaries, contracts) and the labour cost by
 * category. Reversed wages are shown but not counted. Work can be recorded here for any worker.
 *
 * @property-read LengthAwarePaginator<int, WorkEntry>|null $entries Livewire computed property
 * @property-read array{total: Money, by_category: list<array{name: string, basis: PayBasis, quantity: Quantity, amount: Money}>} $totals Livewire computed property
 * @property-read array<int, string> $categoryOptions Livewire computed property
 * @property-read array<int, string> $workerOptions Livewire computed property
 * @property-read Worker|null $formWorker Livewire computed property
 * @property-read LabourRate|null $formRate Livewire computed property
 * @property-read Money|null $formAmount Livewire computed property
 */
class WorkIndex extends Component
{
    use FiltersByDateRange, RunsActions, WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    /** @var array<string, string> */
    public array $form = [];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::LabourView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }

        if (str_starts_with($property, 'form.')) {
            $this->resetErrorBag($property);
        }

        // Another worker may be paid differently: start their figures afresh.
        if ($property === 'form.worker_id') {
            $this->form['quantity'] = '';
            $this->form['amount'] = '';
        }
    }

    /**
     * @return LengthAwarePaginator<int, WorkEntry>|null
     */
    #[Computed]
    public function entries(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['entry', 'worker', 'category', 'productionEntry', 'creator'])
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Labour cost of the period by category (reversed wages left out).
     *
     * @return array{total: Money, by_category: list<array{name: string, basis: PayBasis, quantity: Quantity, amount: Money}>}
     */
    #[Computed]
    public function totals(): array
    {
        $rows = $this->filtered()
            ?->whereHas('entry', fn (Builder $q) => $q->where('status', JournalEntry::STATUS_POSTED))
            ->toBase()
            ->groupBy('labour_category_id')
            ->selectRaw('labour_category_id, SUM(amount) AS amount, SUM(COALESCE(quantity, 0)) AS quantity')
            ->get() ?? collect();

        $categories = LabourCategory::query()->whereKey($rows->pluck('labour_category_id'))->get()->keyBy('id');

        $byCategory = $rows
            ->map(fn ($row) => [
                'name' => $categories[(int) $row->labour_category_id]->displayName(),
                'basis' => $categories[(int) $row->labour_category_id]->basis,
                'quantity' => Quantity::fromMilli((int) $row->quantity),
                'amount' => Money::fromPaisa((int) $row->amount),
            ])
            ->sortByDesc(fn ($row) => $row['amount']->paisa)
            ->values()
            ->all();

        return ['total' => Money::fromPaisa((int) $rows->sum('amount')), 'by_category' => $byCategory];
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return LabourCategory::query()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (LabourCategory $c) => [$c->id => $c->displayName()])->all();
    }

    /**
     * Active workers paid by the piece, the day or by contract (salaries come from attendance).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function workerOptions(): array
    {
        return Worker::query()
            ->with('category')
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $q) => $q->where('basis', '!=', PayBasis::PerMonth->value))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Worker $w) => [$w->id => $w->displayName().' · '.$w->category->displayName()])
            ->all();
    }

    #[Computed]
    public function formWorker(): ?Worker
    {
        $id = (string) ($this->form['worker_id'] ?? '');

        return ctype_digit($id) ? Worker::query()->with('category')->find((int) $id) : null;
    }

    #[Computed]
    public function formRate(): ?LabourRate
    {
        $date = $this->form['work_date'] ?? '';
        if ($this->formWorker === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return app(WorkerLedger::class)->rateFor($this->formWorker, Carbon::parse($date)->startOfDay());
    }

    #[Computed]
    public function formAmount(): ?Money
    {
        $basis = $this->formWorker?->category->basis;
        if ($this->formRate === null || ! in_array($basis, [PayBasis::PerThousand, PayBasis::PerDay], true)) {
            return null;
        }

        try {
            $quantity = Quantity::fromInput((string) ($this->form['quantity'] ?? ''), $basis->decimals());
        } catch (InvalidArgumentException) {
            return null;
        }

        return $quantity->isPositive() ? $this->formRate->rate->timesQuantity($quantity->toDecimal(), $basis === PayBasis::PerThousand ? 1000 : 1) : null;
    }

    public function create(): void
    {
        $this->authorize(Permission::LabourRecord->value);
        $this->resetErrorBag();
        $this->form = ['worker_id' => '', 'work_date' => now()->format('Y-m-d'), 'quantity' => '', 'amount' => '', 'description' => ''];
        $this->formKey = (string) Str::uuid();
        $this->showForm = true;
    }

    /** Save; with $another the form stays open for the next worker (same date). */
    public function save(bool $another = false): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $work = $this->runAction(fn () => app(RecordWorkEntry::class)->handle($actor, $this->form, $this->formKey), 'form');

        $this->toast(__('Wages :number recorded: :amount.', ['number' => $work->entry->number, 'amount' => $work->amount->formatWithCurrency()]));
        unset($this->entries, $this->totals);

        if ($another) {
            $this->form = ['worker_id' => '', 'work_date' => $this->form['work_date'], 'quantity' => '', 'amount' => '', 'description' => ''];
            $this->formKey = (string) Str::uuid();
            $this->dispatch('work-saved');

            return;
        }

        $this->showForm = false;
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.labour.work-index', [
            'canRecord' => $actor->can(Permission::LabourRecord->value),
            'range' => $this->dateRange(),
        ])->title(__('Work and wages'));
    }

    /**
     * @return Builder<WorkEntry>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);

        return WorkEntry::query()
            ->whereBetween('work_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when(ctype_digit($this->category), fn (Builder $q) => $q->where('labour_category_id', (int) $this->category))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereHas('worker', fn (Builder $w) => $w->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%"))
                ->orWhereHas('entry', fn (Builder $e) => $e->where('number', 'like', "%{$search}%"))
                ->orWhere('description', 'like', "%{$search}%")));
    }
}
