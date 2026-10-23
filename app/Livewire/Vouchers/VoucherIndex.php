<?php

namespace App\Livewire\Vouchers;

use App\Actions\Accounting\ReverseEntry;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalEntry;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payment and receipt vouchers for a period, with totals paid and received. Also the
 * "payments and receipts" report: filter, then print.
 *
 * @property-read LengthAwarePaginator<int, JournalEntry>|null $vouchers Livewire computed property
 */
class VoucherIndex extends Component
{
    use FiltersByDateRange, RunsActions, WithPagination;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showReverseForm = false;

    public ?int $reversingId = null;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::AccountsView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'account', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, JournalEntry>|null
     */
    #[Computed]
    public function vouchers(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['lines.account', 'lines.party', 'creator'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Totals of the filtered vouchers that still count (reversed ones are left out).
     *
     * @return array{paid: Money, received: Money, count: int}
     */
    #[Computed]
    public function totals(): array
    {
        $sums = $this->filtered()
            ?->where('status', JournalEntry::STATUS_POSTED)
            ->toBase()
            ->groupBy('type')
            ->selectRaw('type, SUM(total) AS amount, COUNT(*) AS n')
            ->get()
            ->keyBy('type');

        return [
            'paid' => Money::fromPaisa((int) ($sums[JournalType::Payment->value]->amount ?? 0)),
            'received' => Money::fromPaisa((int) ($sums[JournalType::Receipt->value]->amount ?? 0)),
            'count' => (int) collect($sums ?? [])->sum('n'),
        ];
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return TreasuryAccount::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (TreasuryAccount $a) => [$a->id => $a->displayName()])
            ->all();
    }

    public function confirmReverse(int $id): void
    {
        $this->authorize(Permission::TransactionsReverse->value);
        $entry = $this->findVoucher($id);
        $this->resetErrorBag();
        $this->reversingId = $entry->id;
        $this->reverseForm = ['reason' => ''];
        $this->formKey = (string) Str::uuid();
        $this->showReverseForm = true;
    }

    public function reverse(ReverseEntry $reverseEntry): void
    {
        $entry = $this->findVoucher((int) $this->reversingId);

        /** @var User $actor */
        $actor = auth()->user();
        $reversal = $this->runAction(fn () => $reverseEntry->handle($actor, $entry, $this->reverseForm, $this->formKey), 'reverseForm');

        $this->showReverseForm = false;
        $this->toast(__(':number reversed by :reversal.', ['number' => $entry->number, 'reversal' => $reversal->number]));
        unset($this->vouchers, $this->totals);
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

        return view('livewire.vouchers.voucher-index', [
            'canRecord' => $actor->can(Permission::PaymentsRecord->value),
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'range' => $this->dateRange(),
        ])->title(__('Payments and receipts'));
    }

    /**
     * Vouchers matching the filters, or null while the date range is invalid.
     *
     * @return Builder<JournalEntry>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $type = JournalType::tryFrom($this->type);
        $account = ctype_digit($this->account) ? TreasuryAccount::query()->find((int) $this->account) : null;
        $search = trim($this->search);

        return JournalEntry::query()
            ->whereIn('type', $type !== null && $type->isVoucher() ? [$type->value] : [JournalType::Payment->value, JournalType::Receipt->value])
            ->whereBetween('entry_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when($account, fn (Builder $q, TreasuryAccount $a) => $q->whereHas('lines', fn (Builder $l) => $l->where('ledger_account_id', $a->ledger_account_id)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('memo', 'like', "%{$search}%")
                ->orWhereHas('lines', fn (Builder $l) => $l->where('memo', 'like', "%{$search}%")
                    ->orWhereHas('party', fn (Builder $p) => $p->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%")))));
    }

    private function findVoucher(int $id): JournalEntry
    {
        $entry = JournalEntry::query()->findOrFail($id);
        abort_unless($entry->type->isVoucher(), 404);

        return $entry;
    }
}
