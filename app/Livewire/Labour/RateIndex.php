<?php

namespace App\Livewire\Labour;

use App\Actions\Labour\SaveLabourCategory;
use App\Actions\Labour\SaveLabourRate;
use App\Actions\Settings\SetArchived;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\LabourCategory;
use App\Models\LabourRate;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Labour categories and their rate cards. A rate starts on a date; earlier work keeps the rate of
 * its day. A worker can have their own rate (e.g. a salary) instead of the category's.
 *
 * @property-read Collection<int, LabourCategory> $categories Livewire computed property
 * @property-read array<int, list<LabourRate>> $ratesByCategory Livewire computed property
 * @property-read array<int, true> $usedRates Livewire computed property
 * @property-read array<int, string> $rateWorkerOptions Livewire computed property
 * @property-read LabourCategory|null $rateCategory Livewire computed property
 */
class RateIndex extends Component
{
    use RunsActions;

    #[Url(except: 'active')]
    public string $status = 'active';

    public bool $showCategoryForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $categoryForm = [];

    public bool $showRateForm = false;

    /** The rate form is for one worker's own rate (not the category's). */
    public bool $rateForWorker = false;

    /** @var array<string, string> */
    public array $rateForm = [];

    public function mount(): void
    {
        $this->authorize(Permission::LabourView->value);
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'categoryForm.') || str_starts_with($property, 'rateForm.')) {
            $this->resetErrorBag($property);
        }
    }

    /**
     * @return Collection<int, LabourCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return LabourCategory::query()
            ->withCount(['workers' => fn ($q) => $q->where('is_active', true)])
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'archived', fn ($q) => $q->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Every rate of the listed categories (category rates and workers' own), newest first.
     *
     * @return array<int, list<LabourRate>>
     */
    #[Computed]
    public function ratesByCategory(): array
    {
        $grouped = [];
        $rates = LabourRate::query()
            ->with('worker')
            ->whereIn('labour_category_id', $this->categories->pluck('id'))
            ->orderBy('worker_id')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        foreach ($rates as $rate) {
            $grouped[$rate->labour_category_id][] = $rate;
        }

        return $grouped;
    }

    /**
     * Rates that wages were worked out with (they cannot be removed).
     *
     * @return array<int, true>
     */
    #[Computed]
    public function usedRates(): array
    {
        $ids = collect($this->ratesByCategory)->flatten(1)->pluck('id');

        $used = WorkEntry::query()->whereIn('labour_rate_id', $ids)->distinct()->pluck('labour_rate_id')->map(fn ($id) => (int) $id)->all();

        return array_fill_keys($used, true);
    }

    #[Computed]
    public function rateCategory(): ?LabourCategory
    {
        $id = (string) ($this->rateForm['labour_category_id'] ?? '');

        return ctype_digit($id) ? LabourCategory::query()->find((int) $id) : null;
    }

    /**
     * Workers of the category in the rate form.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function rateWorkerOptions(): array
    {
        if ($this->rateCategory === null) {
            return [];
        }

        return Worker::query()
            ->where('labour_category_id', $this->rateCategory->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Worker $w) => [$w->id => $w->displayName()])
            ->all();
    }

    public function createCategory(): void
    {
        $this->authorize(Permission::LabourRates->value);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->categoryForm = ['name' => '', 'name_ur' => '', 'basis' => PayBasis::PerThousand->value, 'activity' => '', 'sort_order' => (string) (((int) LabourCategory::query()->max('sort_order')) + 10)];
        $this->showCategoryForm = true;
    }

    public function editCategory(int $id): void
    {
        $this->authorize(Permission::LabourRates->value);
        $category = LabourCategory::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $category->id;
        $this->categoryForm = [
            'name' => $category->name,
            'name_ur' => (string) $category->name_ur,
            'basis' => $category->basis->value,
            'activity' => $category->activity->value ?? '',
            'sort_order' => (string) $category->sort_order,
        ];
        $this->showCategoryForm = true;
    }

    public function saveCategory(SaveLabourCategory $saveCategory): void
    {
        $category = $this->editingId !== null ? LabourCategory::query()->findOrFail($this->editingId) : null;

        $this->runAction(fn () => $saveCategory->handle($this->actor(), $category, $this->categoryForm), 'categoryForm');

        $this->showCategoryForm = false;
        $this->toast($category ? __('Changes saved.') : __('Category added.'));
        unset($this->categories, $this->ratesByCategory);
    }

    public function archiveCategory(int $id, SetArchived $setArchived): void
    {
        $this->setArchived($id, true, $setArchived);
    }

    public function restoreCategory(int $id, SetArchived $setArchived): void
    {
        $this->setArchived($id, false, $setArchived);
    }

    public function createRate(int $categoryId, bool $forWorker = false): void
    {
        $this->authorize(Permission::LabourRates->value);
        $category = LabourCategory::query()->findOrFail($categoryId);
        $this->resetErrorBag();
        $this->rateForWorker = $forWorker;
        $this->rateForm = [
            'labour_category_id' => (string) $category->id,
            'worker_id' => '',
            'effective_from' => now()->format('Y-m-d'),
            'rate' => '',
        ];
        unset($this->rateCategory, $this->rateWorkerOptions);
        $this->showRateForm = true;
    }

    public function saveRate(SaveLabourRate $saveRate): void
    {
        // Without a worker the action would set the category rate instead.
        if ($this->rateForWorker && ! ctype_digit($this->rateForm['worker_id'] ?? '')) {
            $this->resetErrorBag();
            $this->addError('rateForm.worker_id', __('Choose a worker of this category.'));

            return;
        }
        if (! $this->rateForWorker) {
            $this->rateForm['worker_id'] = '';
        }

        $this->runAction(fn () => $saveRate->handle($this->actor(), $this->rateForm), 'rateForm');

        $this->showRateForm = false;
        $this->toast(__('Rate saved.'));
        unset($this->ratesByCategory, $this->usedRates);
    }

    public function removeRate(int $id, SaveLabourRate $saveRate): void
    {
        $rate = LabourRate::query()->with(['worker', 'category'])->findOrFail($id);

        try {
            $saveRate->remove($this->actor(), $rate);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Rate removed.'));
        unset($this->ratesByCategory, $this->usedRates);
    }

    public function render(): View
    {
        return view('livewire.labour.rate-index', [
            'canManage' => $this->actor()->can(Permission::LabourRates->value),
        ])->title(__('Categories and rates'));
    }

    private function setArchived(int $id, bool $archived, SetArchived $setArchived): void
    {
        $category = LabourCategory::query()->findOrFail($id);

        try {
            $setArchived->handle($this->actor(), $category, $archived, Permission::LabourRates);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($archived ? __('Archived. It no longer appears in new entries.') : __('Restored.'));
        unset($this->categories, $this->ratesByCategory);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
