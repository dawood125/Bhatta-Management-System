<?php

namespace App\Livewire\Vouchers;

use App\Actions\Accounting\ReverseEntry;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * One payment or receipt voucher: its lines, who recorded it, its reversal if any. Printable as
 * a voucher slip with signature lines.
 */
class VoucherShow extends Component
{
    use RunsActions;

    public JournalEntry $entry;

    public bool $showReverseForm = false;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public string $formKey = '';

    public function mount(JournalEntry $entry): void
    {
        $this->authorize(Permission::AccountsView->value);
        abort_unless($entry->type->isVoucher(), 404);
        $this->entry = $entry;
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
        /** @var User $actor */
        $actor = auth()->user();
        $reversal = $this->runAction(fn () => $reverseEntry->handle($actor, $this->entry, $this->reverseForm, $this->formKey), 'reverseForm');

        $this->showReverseForm = false;
        $this->toast(__(':number reversed by :reversal.', ['number' => $this->entry->number, 'reversal' => $reversal->number]));
        $this->entry->refresh();
    }

    public function render(): View
    {
        $this->entry->load(['lines.account', 'lines.party', 'creator', 'reversedBy.creator']);

        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.vouchers.voucher-show', [
            'main' => $this->entry->lines->firstWhere('line_no', 1),
            'lines' => $this->entry->lines->where('line_no', '>', 1)->values(),
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'canRecord' => $actor->can(Permission::PaymentsRecord->value),
        ])->title($this->entry->number);
    }
}
