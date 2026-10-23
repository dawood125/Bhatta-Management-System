<?php

namespace App\Livewire\Vouchers;

use App\Accounting\LedgerQueries;
use App\Actions\Vouchers\RecordVoucher;
use App\Enums\AccountType;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A new payment or receipt voucher: one cash/bank account and one or more lines, each to a party
 * or to another account. Shows the selected parties' balances and the account balance after saving.
 *
 * @property-read JournalType $type Livewire computed property
 * @property-read Collection<int, TreasuryAccount> $accounts Livewire computed property
 * @property-read array<int, Money> $accountBalances Livewire computed property
 * @property-read array<int, Money> $partyBalances Livewire computed property
 * @property-read Money|null $total Livewire computed property
 */
class VoucherForm extends Component
{
    use RunsActions;

    #[Locked]
    public string $kind = '';

    /** @var array<string, mixed> */
    public array $form = [];

    /** One-time key of this voucher: a double submit can never post twice. */
    public string $formKey = '';

    /** The party list is sent with the first render only (it can be long); the page keeps it. */
    public bool $optionsSent = false;

    public function mount(string $kind): void
    {
        $type = JournalType::tryFrom($kind);
        abort_unless($type !== null && $type->isVoucher(), 404);
        $this->authorize(Permission::PaymentsRecord->value);

        $this->kind = $type->value;
        $this->form = [
            'account_id' => (string) ($this->accounts->first()->id ?? ''),
            'entry_date' => now()->format('Y-m-d'),
            'memo' => '',
            'lines' => $this->prefilledLines() ?: [$this->blankLine()],
        ];
        $this->formKey = (string) Str::uuid();
    }

    /**
     * Lines handed over by another page, e.g. "Pay drivers" from the drivers-due list:
     * ?lines=12:6000,15:3000 (party id : amount). Unknown or archived parties are left out.
     *
     * @return list<array<string, string>>
     */
    private function prefilledLines(): array
    {
        $pairs = [];
        foreach (explode(',', (string) request()->query('lines', '')) as $pair) {
            [$id, $amount] = array_pad(explode(':', trim($pair), 2), 2, '');
            if (ctype_digit($id) && preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) === 1) {
                $pairs[(int) $id] = $amount;
            }
        }

        if ($pairs === []) {
            return [];
        }

        $parties = Party::query()->where('is_active', true)->whereKey(array_keys($pairs))->pluck('id')->all();
        $lines = [];
        foreach (array_slice($pairs, 0, RecordVoucher::MAX_LINES, true) as $id => $amount) {
            if (in_array($id, $parties, true)) {
                $lines[] = ['party_id' => (string) $id, 'amount' => $amount] + $this->blankLine();
            }
        }

        return $lines;
    }

    public function rendered(): void
    {
        $this->optionsSent = true;
    }

    #[Computed]
    public function type(): JournalType
    {
        return JournalType::from($this->kind);
    }

    /**
     * @return Collection<int, TreasuryAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return TreasuryAccount::query()
            ->with('ledgerAccount')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * Current balance of each active account, by treasury account id.
     *
     * @return array<int, Money>
     */
    #[Computed]
    public function accountBalances(): array
    {
        $byLedger = app(LedgerQueries::class)->balances($this->accounts->pluck('ledger_account_id')->all());

        return $this->accounts->mapWithKeys(fn (TreasuryAccount $a) => [$a->id => $byLedger[$a->ledger_account_id]])->all();
    }

    /**
     * Searchable list of active parties for the line pickers.
     *
     * @return list<array{value: int, label: string, hint: string, search: string}>
     */
    public function partyOptions(): array
    {
        $parties = Party::query()->where('is_active', true)->orderBy('name')->get();
        $roles = DB::table('party_roles')->whereIn('party_id', $parties->pluck('id'))->get()->groupBy('party_id');

        return $parties
            ->map(function (Party $party) use ($roles) {
                $labels = collect($roles[$party->id] ?? [])->map(fn ($row) => PartyRole::from($row->role)->label())->sort()->implode(', ');

                return [
                    'value' => $party->id,
                    'label' => $party->displayName(),
                    'hint' => $labels,
                    'search' => mb_strtolower(implode(' ', array_filter([$party->name, $party->name_ur, $party->phone, $party->phone_2]))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Balances of the parties chosen on the lines.
     *
     * @return array<int, Money>
     */
    #[Computed]
    public function partyBalances(): array
    {
        $ids = collect($this->lines())
            ->filter(fn ($line) => ($line['type'] ?? '') === RecordVoucher::LINE_PARTY && ctype_digit((string) ($line['party_id'] ?? '')))
            ->map(fn ($line) => (int) $line['party_id'])
            ->unique()
            ->values()
            ->all();

        return app(LedgerQueries::class)->partyBalances($ids);
    }

    /** Sum of the amounts typed so far; null while one of them is not a valid amount yet. */
    #[Computed]
    public function total(): ?Money
    {
        $total = Money::zero();

        foreach ($this->lines() as $line) {
            $amount = trim((string) ($line['amount'] ?? ''));
            if ($amount === '') {
                continue;
            }

            try {
                $total = $total->add(Money::fromRupees($amount));
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $total;
    }

    /** Balance of the voucher's account after this voucher, in its natural direction. */
    public function balanceAfter(): ?Money
    {
        $account = $this->accounts->get((int) ($this->form['account_id'] ?? 0));
        if ($account === null || $this->total === null) {
            return null;
        }

        $balance = $this->accountBalances[$account->id];
        // Money out of a cash/bank account lowers it; on a credit card it raises what is owed.
        $lowers = ($this->type === JournalType::Payment) === ($account->ledgerAccount->type !== AccountType::Liability);

        return $lowers ? $balance->subtract($this->total) : $balance->add($this->total);
    }

    public function addLine(): void
    {
        if (count($this->form['lines']) >= RecordVoucher::MAX_LINES) {
            return;
        }

        // A new line starts like the one above it (party or account), which is usually the case.
        $last = end($this->form['lines']);
        $this->form['lines'][] = $this->blankLine(is_array($last) ? (string) ($last['type'] ?? RecordVoucher::LINE_PARTY) : RecordVoucher::LINE_PARTY);
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);

        if ($this->form['lines'] === []) {
            $this->form['lines'][] = $this->blankLine();
        }

        $this->resetErrorBag();
    }

    public function updated(string $property): void
    {
        // A field the person has just changed no longer shows its old error.
        $this->resetErrorBag($property);

        // Switching a line between party and account clears the old choice.
        if (preg_match('/^form\.lines\.(\d+)\.type$/', $property, $match) === 1 && isset($this->form['lines'][(int) $match[1]])) {
            $this->form['lines'][(int) $match[1]]['party_id'] = '';
            $this->form['lines'][(int) $match[1]]['account_id'] = '';
        }
    }

    public function save(RecordVoucher $recordVoucher): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $entry = $this->runAction(fn () => $recordVoucher->handle($actor, $this->type, $this->form, $this->formKey), 'form');

        session()->flash('toast', ['type' => 'success', 'message' => __(':number recorded.', ['number' => $entry->number])]);
        $this->redirectRoute('vouchers.show', ['entry' => $entry->id], navigate: true);
    }

    public function render(): View
    {
        $isPayment = $this->type === JournalType::Payment;

        return view('livewire.vouchers.voucher-form', [
            'isPayment' => $isPayment,
            'balanceAfter' => $this->balanceAfter(),
        ])->title($isPayment ? __('New payment') : __('New receipt'));
    }

    /**
     * The form's lines (Livewire state can be anything the browser sends, so check the shape).
     *
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        $lines = $this->form['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    /**
     * @return array{key: string, type: string, party_id: string, account_id: string, amount: string, memo: string}
     */
    private function blankLine(string $type = RecordVoucher::LINE_PARTY): array
    {
        return ['key' => (string) Str::uuid(), 'type' => $type, 'party_id' => '', 'account_id' => '', 'amount' => '', 'memo' => ''];
    }
}
