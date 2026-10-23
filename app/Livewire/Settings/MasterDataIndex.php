<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SetArchived;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Concerns\Archivable;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Shared behaviour of the simple master-data screens (products, units, areas, expense
 * categories): list, search, show active/archived, add, edit, archive, restore.
 *
 * Records are always looked up through the kiln-scoped model, so ids of another kiln are
 * "not found".
 *
 * @template TModel of Model&Archivable
 */
abstract class MasterDataIndex extends Component
{
    use RunsActions, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @return class-string<TModel> */
    abstract protected function modelClass(): string;

    /** @return array<string, mixed> */
    abstract protected function blankForm(): array;

    /**
     * @param  TModel  $record
     * @return array<string, mixed>
     */
    abstract protected function formFrom(Model $record): array;

    /**
     * Save through the module's action.
     *
     * @param  TModel|null  $record
     */
    abstract protected function persist(User $actor, ?Model $record): Model;

    abstract protected function viewName(): string;

    abstract protected function pageTitle(): string;

    /**
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function sortQuery(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * @return Builder<TModel>
     */
    protected function baseQuery(): Builder
    {
        return ($this->modelClass())::query();
    }

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $this->form = $this->blankForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    #[Computed]
    public function records(): LengthAwarePaginator
    {
        $search = trim($this->search);

        $query = $this->baseQuery()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ur', 'like', "%{$search}%")))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'archived', fn (Builder $q) => $q->where('is_active', false));

        return $this->sortQuery($query)->orderBy('id')->paginate(25);
    }

    public function create(): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $record = $this->findRecord($id);
        $this->resetErrorBag();
        $this->editingId = (int) $record->getKey();
        $this->form = $this->formFrom($record);
        $this->showForm = true;
    }

    public function save(): void
    {
        $record = $this->editingId !== null ? $this->findRecord($this->editingId) : null;

        $this->runAction(fn () => $this->persist($this->actor(), $record), 'form');

        $this->showForm = false;
        $this->toast($record ? __('Changes saved.') : __('Added.'));
        unset($this->records);
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
        return view($this->viewName())->title($this->pageTitle());
    }

    /**
     * @return TModel
     */
    protected function findRecord(int $id): Model
    {
        return $this->baseQuery()->findOrFail($id);
    }

    protected function actor(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function setArchived(int $id, bool $archived, SetArchived $setArchived): void
    {
        $record = $this->findRecord($id);

        try {
            $setArchived->handle($this->actor(), $record, $archived);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($archived ? __('Archived. It no longer appears in new entries.') : __('Restored.'));
        unset($this->records);
    }
}
