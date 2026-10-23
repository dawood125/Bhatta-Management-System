<?php

namespace App\Livewire\Labour;

use App\Actions\Labour\SaveWorker;
use App\Actions\Settings\SetArchived;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Livewire\Concerns\RunsActions;
use App\Models\LabourCategory;
use App\Models\User;
use App\Models\Worker;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Workers with their live balances (wages due or advance), and the form to add or edit one.
 *
 * @property-read LengthAwarePaginator<int, Worker> $workers Livewire computed property
 * @property-read array<int, Money> $balances Livewire computed property
 * @property-read array{owed_to_workers: Money, advances_out: Money} $totals Livewire computed property
 * @property-read array<int, string> $categoryOptions Livewire computed property
 * @property-read array<int, string> $formCategoryOptions Livewire computed property
 * @property-read Collection<int, Worker> $similarWorkers Livewire computed property
 */
class WorkerIndex extends Component
{
    use RunsActions, WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize(Permission::LabourView->value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'search', 'status'], true)) {
            $this->resetPage();
        }

        if (str_starts_with($property, 'form.')) {
            $this->resetErrorBag($property);
        }
    }

    /**
     * @return LengthAwarePaginator<int, Worker>
     */
    #[Computed]
    public function workers(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Worker::query()
            ->with('category')
            ->when(ctype_digit($this->category), fn (Builder $q) => $q->where('labour_category_id', (int) $this->category))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ur', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'archived', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * Balances of the workers on the current page.
     *
     * @return array<int, Money>
     */
    #[Computed]
    public function balances(): array
    {
        return app(WorkerLedger::class)->balances(collect($this->workers->items())->pluck('id')->all());
    }

    /**
     * @return array{owed_to_workers: Money, advances_out: Money}
     */
    #[Computed]
    public function totals(): array
    {
        return app(WorkerLedger::class)->totals();
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
     * Active categories, plus the worker's current one.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function formCategoryOptions(): array
    {
        $selected = (int) ($this->form['labour_category_id'] ?? 0);

        return LabourCategory::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $selected))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (LabourCategory $c) => [$c->id => $c->displayName().' · '.$c->basis->label()])->all();
    }

    /**
     * Existing workers with a similar name, to avoid adding the same person twice.
     *
     * @return Collection<int, Worker>
     */
    #[Computed]
    public function similarWorkers(): Collection
    {
        $name = trim((string) ($this->form['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            return collect();
        }

        return Worker::query()
            ->with('category')
            ->where(fn (Builder $q) => $q->where('name', 'like', "%{$name}%")->orWhere('name_ur', 'like', "%{$name}%"))
            ->when($this->editingId, fn (Builder $q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    public function create(): void
    {
        $this->authorize(Permission::LabourWorkers->value);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'name_ur' => '',
            'phone' => '',
            'labour_category_id' => ctype_digit($this->category) ? $this->category : '',
            'joined_on' => '',
            'notes' => '',
        ];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permission::LabourWorkers->value);
        $worker = Worker::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $worker->id;
        $this->form = [
            'name' => $worker->name,
            'name_ur' => (string) $worker->name_ur,
            'phone' => (string) $worker->phone,
            'labour_category_id' => (string) $worker->labour_category_id,
            'joined_on' => $worker->joined_on?->format('Y-m-d') ?? '',
            'notes' => (string) $worker->notes,
        ];
        $this->showForm = true;
    }

    public function save(SaveWorker $saveWorker): void
    {
        $worker = $this->editingId !== null ? Worker::query()->findOrFail($this->editingId) : null;

        $this->runAction(fn () => $saveWorker->handle($this->actor(), $worker, $this->form), 'form');

        $this->showForm = false;
        $this->toast($worker ? __('Changes saved.') : __('Worker added.'));
        unset($this->workers, $this->balances);
    }

    public function archive(int $id, SetArchived $setArchived): void
    {
        $this->setArchived($id, true, $setArchived);
    }

    public function restore(int $id, SetArchived $setArchived): void
    {
        $this->setArchived($id, false, $setArchived);
    }

    public function render(): View
    {
        return view('livewire.labour.worker-index', [
            'canManage' => $this->actor()->can(Permission::LabourWorkers->value),
        ])->title(__('Workers'));
    }

    private function setArchived(int $id, bool $archived, SetArchived $setArchived): void
    {
        $worker = Worker::query()->findOrFail($id);

        try {
            $setArchived->handle($this->actor(), $worker, $archived, Permission::LabourWorkers);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($archived ? __('Archived. It no longer appears in new entries.') : __('Restored.'));
        unset($this->workers, $this->balances);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
