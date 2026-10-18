<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveArea;
use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends MasterDataIndex<Area>
 */
class AreaIndex extends MasterDataIndex
{
    protected function modelClass(): string
    {
        return Area::class;
    }

    protected function viewName(): string
    {
        return 'livewire.settings.area-index';
    }

    protected function pageTitle(): string
    {
        return __('Areas');
    }

    protected function blankForm(): array
    {
        return ['name' => '', 'name_ur' => ''];
    }

    protected function formFrom(Model $record): array
    {
        return ['name' => $record->name, 'name_ur' => $record->name_ur];
    }

    protected function persist(User $actor, ?Model $record): Model
    {
        return app(SaveArea::class)->handle($actor, $record, $this->form);
    }
}
