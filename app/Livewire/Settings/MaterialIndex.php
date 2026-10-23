<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveMaterial;
use App\Enums\MaterialKind;
use App\Models\Material;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;

/**
 * Materials the kiln buys and uses (soil, Kassu, coal, Bora, ...), with their units.
 *
 * @extends MasterDataIndex<Material>
 */
class MaterialIndex extends MasterDataIndex
{
    protected function modelClass(): string
    {
        return Material::class;
    }

    protected function viewName(): string
    {
        return 'livewire.settings.material-index';
    }

    protected function pageTitle(): string
    {
        return __('Materials');
    }

    protected function baseQuery(): Builder
    {
        return Material::query()->with('unit');
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
            'kind' => MaterialKind::RawMaterial->value,
            'unit_id' => '',
            'sort_order' => (string) (((int) Material::query()->max('sort_order')) + 10),
        ];
    }

    protected function formFrom(Model $record): array
    {
        return [
            'name' => $record->name,
            'name_ur' => $record->name_ur,
            'kind' => $record->kind->value,
            'unit_id' => (string) $record->unit_id,
            'sort_order' => (string) $record->sort_order,
        ];
    }

    protected function persist(User $actor, ?Model $record): Model
    {
        return app(SaveMaterial::class)->handle($actor, $record, $this->form);
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
