<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends MasterDataIndex<Unit>
 */
class UnitIndex extends MasterDataIndex
{
    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function viewName(): string
    {
        return 'livewire.settings.unit-index';
    }

    protected function pageTitle(): string
    {
        return __('Units');
    }

    protected function sortQuery(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    protected function blankForm(): array
    {
        return ['name' => '', 'name_ur' => '', 'symbol' => '', 'decimals' => '0'];
    }

    protected function formFrom(Model $record): array
    {
        return [
            'name' => $record->name,
            'name_ur' => $record->name_ur,
            'symbol' => $record->symbol,
            'decimals' => (string) $record->decimals,
        ];
    }

    protected function persist(User $actor, ?Model $record): Model
    {
        return app(SaveUnit::class)->handle($actor, $record, $this->form);
    }

    public function editingSystemUnit(): bool
    {
        return $this->editingId !== null && (bool) Unit::query()->whereKey($this->editingId)->value('is_system');
    }
}
