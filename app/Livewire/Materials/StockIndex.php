<?php

namespace App\Livewire\Materials;

use App\Actions\Materials\RecordMaterialAdjustment;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Materials\MaterialStock;
use App\Models\Material;
use App\Models\MaterialAdjustment;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Material stock: quantity, average cost and value of each material, and the total value.
 * Owners and managers enter opening stock and shortages here.
 *
 * @property-read Collection<int, Material> $materials Livewire computed property
 * @property-read array<int, array{quantity: Quantity, value: Money}> $levels Livewire computed property
 */
class StockIndex extends Component
{
    use RunsActions;

    #[Url(as: 'archived', except: false)]
    public bool $showArchived = false;

    public bool $showAdjustForm = false;

    /** @var array<string, string> */
    public array $adjustForm = [];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::MaterialsView->value);
    }

    /**
     * @return Collection<int, Material>
     */
    #[Computed]
    public function materials(): Collection
    {
        return Material::query()
            ->with('unit')
            ->where('is_active', ! $this->showArchived)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, array{quantity: Quantity, value: Money}>
     */
    #[Computed]
    public function levels(): array
    {
        return app(MaterialStock::class)->levels($this->materials->pluck('id')->all());
    }

    #[Computed]
    public function totalValue(): Money
    {
        return Money::sum(array_column($this->levels, 'value'));
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function materialOptions(): array
    {
        return Material::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (Material $m) => [$m->id => $m->displayName()])->all();
    }

    /** The material chosen in the adjustment form, with its stock. */
    #[Computed]
    public function adjustMaterial(): ?Material
    {
        $id = $this->adjustForm['material_id'] ?? '';

        return ctype_digit($id) ? Material::query()->with('unit')->find((int) $id) : null;
    }

    public function openAdjust(string $kind = MaterialAdjustment::OPENING, ?int $materialId = null): void
    {
        $this->authorize(Permission::MaterialsAdjust->value);
        $this->resetErrorBag();
        $this->adjustForm = [
            'kind' => $kind === MaterialAdjustment::SHORTAGE ? MaterialAdjustment::SHORTAGE : MaterialAdjustment::OPENING,
            'material_id' => $materialId !== null ? (string) $materialId : '',
            'adjustment_date' => now()->format('Y-m-d'),
            'quantity' => '',
            'value' => '',
            'reason' => '',
        ];
        $this->formKey = (string) Str::uuid();
        $this->showAdjustForm = true;
    }

    public function updated(string $property): void
    {
        // A field the person has just changed no longer shows its old error.
        if (str_starts_with($property, 'adjustForm.')) {
            $this->resetErrorBag($property);
        }
    }

    public function saveAdjust(RecordMaterialAdjustment $record): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $adjustment = $this->runAction(fn () => $record->handle($actor, $this->adjustForm, $this->formKey), 'adjustForm');

        $this->showAdjustForm = false;
        $this->toast(__(':number recorded.', ['number' => $adjustment->entry->number]));
        unset($this->levels, $this->totalValue);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.materials.stock-index', [
            'canRecord' => $actor->can(Permission::MaterialsRecord->value),
            'canAdjust' => $actor->can(Permission::MaterialsAdjust->value),
            'stock' => app(MaterialStock::class),
        ])->title(__('Materials'));
    }
}
