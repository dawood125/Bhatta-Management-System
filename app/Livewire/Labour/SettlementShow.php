<?php

namespace App\Livewire\Labour;

use App\Actions\Accounting\ReverseEntry;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Settlement;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * One settlement with every worker's figures. Prints as one payslip per worker. Reversing it
 * cancels the payment; the workers then owe nothing less and are owed again what was paid.
 */
class SettlementShow extends Component
{
    use RunsActions;

    public Settlement $settlement;

    public bool $showReverseForm = false;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public string $formKey = '';

    public function mount(Settlement $settlement): void
    {
        $this->authorize(Permission::LabourView->value);
        $this->settlement = $settlement;
    }

    public function confirmReverse(): void
    {
        $this->authorize(Permission::TransactionsReverse->value);
        $this->resetErrorBag();
        $this->reverseForm = ['reason' => ''];
        $this->formKey = (string) Str::uuid();
        $this->showReverseForm = true;
    }

    public function reverse(ReverseEntry $reverseEntry): void
    {
        $entry = $this->settlement->entry;
        if ($entry === null) {
            return;
        }

        /** @var User $actor */
        $actor = auth()->user();
        $reversal = $this->runAction(fn () => $reverseEntry->handle($actor, $entry, $this->reverseForm, $this->formKey), 'reverseForm');

        $this->showReverseForm = false;
        $this->settlement->unsetRelation('entry');
        $this->toast(__(':number reversed by :reversal.', ['number' => $this->settlement->number, 'reversal' => $reversal->number]));
    }

    public function render(): View
    {
        $this->settlement->loadMissing(['entry.reversedBy', 'treasuryAccount', 'creator', 'lines.worker.category']);

        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.labour.settlement-show', [
            'canReverse' => $actor->can(Permission::TransactionsReverse->value) && $this->settlement->entry?->canBeReversed() === true,
            'kiln' => app(CurrentKiln::class)->get(),
        ])->title(__('Settlement').' '.$this->settlement->number);
    }
}
