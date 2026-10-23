<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveProduct;
use App\Enums\ProductKind;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;

/**
 * @extends MasterDataIndex<Product>
 */
class ProductIndex extends MasterDataIndex
{
    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function viewName(): string
    {
        return 'livewire.settings.product-index';
    }

    protected function pageTitle(): string
    {
        return __('Products and grades');
    }

    protected function baseQuery(): Builder
    {
        return Product::query()->with('unit');
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
            'kind' => ProductKind::FiredGrade->value,
            'unit_id' => (string) Unit::query()->where('code', Unit::BRICK)->value('id'),
            'is_saleable' => true,
            'sort_order' => (string) (((int) Product::query()->max('sort_order')) + 10),
        ];
    }

    protected function formFrom(Model $record): array
    {
        return [
            'name' => $record->name,
            'name_ur' => $record->name_ur,
            'kind' => $record->kind->value,
            'unit_id' => (string) $record->unit_id,
            'is_saleable' => $record->is_saleable,
            'sort_order' => (string) $record->sort_order,
        ];
    }

    protected function persist(User $actor, ?Model $record): Model
    {
        return app(SaveProduct::class)->handle($actor, $record, $this->form);
    }

    /**
     * Active units, plus the currently selected one even if it was archived meanwhile.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function unitOptions(): array
    {
        $selected = (int) ($this->form['unit_id'] ?? 0);

        return Unit::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $selected))
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (Unit $unit) => [$unit->id => $unit->displayName()])
            ->all();
    }
}
