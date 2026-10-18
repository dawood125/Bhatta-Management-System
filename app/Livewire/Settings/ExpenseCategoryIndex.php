<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveExpenseCategory;
use App\Actions\Settings\SaveExpenseSubCategory;
use App\Actions\Settings\SetArchived;
use App\Enums\Permission;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Expense categories with their sub-categories.
 *
 * @extends MasterDataIndex<ExpenseCategory>
 */
class ExpenseCategoryIndex extends MasterDataIndex
{
    public bool $showSubForm = false;

    public ?int $subEditingId = null;

    /** @var array<string, mixed> */
    public array $subForm = ['expense_category_id' => '', 'name' => '', 'name_ur' => ''];

    protected function modelClass(): string
    {
        return ExpenseCategory::class;
    }

    protected function viewName(): string
    {
        return 'livewire.settings.expense-category-index';
    }

    protected function pageTitle(): string
    {
        return __('Expense categories');
    }

    protected function baseQuery(): Builder
    {
        return ExpenseCategory::query()->with(['subCategories' => fn ($q) => $q->orderBy('name')]);
    }

    protected function sortQuery(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    protected function blankForm(): array
    {
        return [
            'name' => '',
            'name_ur' => '',
            'sort_order' => (string) (((int) ExpenseCategory::query()->max('sort_order')) + 10),
        ];
    }

    protected function formFrom(Model $record): array
    {
        return ['name' => $record->name, 'name_ur' => $record->name_ur, 'sort_order' => (string) $record->sort_order];
    }

    protected function persist(User $actor, ?Model $record): Model
    {
        return app(SaveExpenseCategory::class)->handle($actor, $record, $this->form);
    }

    public function createSub(int $categoryId): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $category = $this->findRecord($categoryId);
        $this->resetErrorBag();
        $this->subEditingId = null;
        $this->subForm = ['expense_category_id' => (string) $category->id, 'name' => '', 'name_ur' => ''];
        $this->showSubForm = true;
    }

    public function editSub(int $subId): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $sub = ExpenseSubCategory::query()->findOrFail($subId);
        $this->resetErrorBag();
        $this->subEditingId = $sub->id;
        $this->subForm = ['expense_category_id' => (string) $sub->expense_category_id, 'name' => $sub->name, 'name_ur' => $sub->name_ur];
        $this->showSubForm = true;
    }

    public function saveSub(SaveExpenseSubCategory $saveSub): void
    {
        $sub = $this->subEditingId !== null ? ExpenseSubCategory::query()->findOrFail($this->subEditingId) : null;

        $this->runAction(fn () => $saveSub->handle($this->actor(), $sub, $this->subForm), 'subForm');

        $this->showSubForm = false;
        $this->toast($sub ? __('Changes saved.') : __('Added.'));
        unset($this->records);
    }

    public function archiveSub(int $subId, SetArchived $setArchived): void
    {
        $this->setSubArchived($subId, true, $setArchived);
    }

    public function restoreSub(int $subId, SetArchived $setArchived): void
    {
        $this->setSubArchived($subId, false, $setArchived);
    }

    private function setSubArchived(int $subId, bool $archived, SetArchived $setArchived): void
    {
        $sub = ExpenseSubCategory::query()->findOrFail($subId);

        try {
            $setArchived->handle($this->actor(), $sub, $archived);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($archived ? __('Archived. It no longer appears in new entries.') : __('Restored.'));
        unset($this->records);
    }
}
