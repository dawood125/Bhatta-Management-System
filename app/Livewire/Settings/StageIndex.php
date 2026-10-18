<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\RenameStage;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\ProductionStage;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The fixed production stages. Kilns can rename them to their own words.
 */
class StageIndex extends Component
{
    use RunsActions;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = ['name' => '', 'name_ur' => ''];

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage->value);
    }

    /**
     * @return Collection<int, ProductionStage>
     */
    #[Computed]
    public function stages(): Collection
    {
        return ProductionStage::query()->orderBy('sort_order')->get();
    }

    public function edit(int $id): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $stage = ProductionStage::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $stage->id;
        $this->form = ['name' => $stage->name, 'name_ur' => $stage->name_ur];
        $this->showForm = true;
    }

    public function save(RenameStage $renameStage): void
    {
        $stage = ProductionStage::query()->findOrFail((int) $this->editingId);

        /** @var User $actor */
        $actor = auth()->user();
        $this->runAction(fn () => $renameStage->handle($actor, $stage, $this->form), 'form');

        $this->showForm = false;
        $this->toast(__('Changes saved.'));
        unset($this->stages);
    }

    public function render(): View
    {
        return view('livewire.settings.stage-index')->title(__('Production stages'));
    }
}
