<?php

namespace App\Livewire\Accounts;

use App\Actions\Accounting\ReverseEntry;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every money transaction of the kiln, newest first, with its debit/credit lines. Mistakes are
 * corrected here by reversing the transaction (with a reason); nothing is ever deleted.
 */
class TransactionIndex extends Component
{
    use RunsActions, WithPagination;

    #[Url(except: '')]
    public string $type = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $expandedId = null;

    // Reverse form
    public bool $showReverseForm = false;

    public ?int $reversingId = null;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::AccountsView->value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return JournalEntry::query()
            ->with(['lines.account', 'lines.party', 'lines.worker', 'creator', 'reversalOf'])
            ->when(JournalType::tryFrom($this->type), fn (Builder $q, JournalType $type) => $q->where('type', $type))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('memo', 'like', "%{$search}%")))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function typeOptions(): array
    {
        $options = ['' => __('All types')];
        foreach (JournalType::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function confirmReverse(int $id): void
    {
        $this->authorize(Permission::TransactionsReverse->value);
        $entry = JournalEntry::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->reversingId = $entry->id;
        $this->reverseForm = ['reason' => ''];
        $this->formKey = (string) Str::uuid();
        $this->showReverseForm = true;
    }

    public function reverse(ReverseEntry $reverseEntry): void
    {
        $entry = JournalEntry::query()->findOrFail((int) $this->reversingId);

        /** @var User $actor */
        $actor = auth()->user();
        $reversal = $this->runAction(fn () => $reverseEntry->handle($actor, $entry, $this->reverseForm, $this->formKey), 'reverseForm');

        $this->showReverseForm = false;
        $this->toast(__(':number reversed by :reversal.', ['number' => $entry->number, 'reversal' => $reversal->number]));
        unset($this->entries);
    }

    #[Computed]
    public function reversing(): ?JournalEntry
    {
        return $this->reversingId ? JournalEntry::query()->find($this->reversingId) : null;
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.accounts.transaction-index', [
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
        ])->title(__('Transactions'));
    }
}
