<?php

namespace App\Livewire\Materials;

use App\Enums\Permission;
use App\Livewire\Concerns\ReversesMaterialEntries;
use App\Livewire\Concerns\RunsActions;
use App\Models\MaterialPurchase;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * One material purchase: materials with quantity, rate and landed cost, the extra charges and
 * who was paid. Printable; reversible by the owner or manager.
 */
class PurchaseShow extends Component
{
    use ReversesMaterialEntries, RunsActions;

    public MaterialPurchase $purchase;

    public function mount(MaterialPurchase $purchase): void
    {
        $this->authorize(Permission::MaterialsView->value);
        $this->purchase = $purchase;
    }

    protected function afterReverse(): void
    {
        $this->purchase->unsetRelation('entry');
    }

    public function render(): View
    {
        $this->purchase->load(['entry.reversedBy.creator', 'supplier', 'treasuryAccount', 'lines.material.unit', 'charges.party', 'charges.treasuryAccount', 'creator']);

        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.materials.purchase-show', [
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'canRecord' => $actor->can(Permission::MaterialsRecord->value),
        ])->title($this->purchase->entry->number);
    }
}
