<?php

namespace App\Livewire\Accounts;

use App\Accounting\LedgerQueries;
use App\Actions\Accounting\RecordCapital;
use App\Actions\Accounting\RecordTransfer;
use App\Actions\Accounting\SaveTreasuryAccount;
use App\Actions\Accounting\SetTreasuryAccountArchived;
use App\Enums\AccountType;
use App\Enums\Permission;
use App\Enums\TreasuryKind;
use App\Livewire\Concerns\RunsActions;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cash and bank accounts with live balances, plus the forms that move money between them.
 *
 * @property-read Collection<int, TreasuryAccount> $accounts Livewire computed property
 * @property-read array<int, Money> $balances Livewire computed property
 */
class AccountIndex extends Component
{
    use RunsActions;

    #[Url(as: 'archived', except: false)]
    public bool $showArchived = false;

    // Account form
    public bool $showAccountForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $accountForm = [];

    // Transfer form
    public bool $showTransferForm = false;

    /** @var array<string, mixed> */
    public array $transferForm = [];

    // Capital form
    public bool $showCapitalForm = false;

    /** @var array<string, mixed> */
    public array $capitalForm = [];

    /** One-time key of the open form: a double submit can never post twice. */
    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::AccountsView->value);
    }

    /**
     * @return Collection<int, TreasuryAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return TreasuryAccount::query()
            ->with('ledgerAccount')
            ->where('is_active', ! $this->showArchived)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, Money>
     */
    #[Computed]
    public function balances(): array
    {
        return app(LedgerQueries::class)->balances($this->accounts->pluck('ledger_account_id')->all());
    }

    /** Money held minus credit-card debt, across active accounts. */
    #[Computed]
    public function netPosition(): Money
    {
        $total = Money::zero();
        foreach ($this->accounts as $account) {
            $balance = $this->balances[$account->ledger_account_id];
            $total = $account->ledgerAccount->type === AccountType::Liability ? $total->subtract($balance) : $total->add($balance);
        }

        return $total;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return TreasuryAccount::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (TreasuryAccount $account) => [$account->id => $account->displayName()])
            ->all();
    }

    public function createAccount(): void
    {
        $this->authorize(Permission::AccountsManage->value);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->accountForm = [
            'kind' => TreasuryKind::Cash->value,
            'name' => '',
            'name_ur' => '',
            'account_number' => '',
            'opening_balance' => '0',
            'opening_date' => now()->format('Y-m-d'),
            'allow_negative' => false,
        ];
        $this->formKey = (string) Str::uuid();
        $this->showAccountForm = true;
    }

    public function editAccount(int $id): void
    {
        $this->authorize(Permission::AccountsManage->value);
        $account = TreasuryAccount::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $account->id;
        $this->accountForm = [
            'kind' => $account->kind->value,
            'name' => $account->name,
            'name_ur' => $account->name_ur,
            'account_number' => $account->account_number,
            'opening_balance' => $account->opening_balance->toDecimal(),
            'opening_date' => $account->opening_date->format('Y-m-d'),
            'allow_negative' => $account->allow_negative,
        ];
        $this->formKey = (string) Str::uuid();
        $this->showAccountForm = true;
    }

    public function saveAccount(SaveTreasuryAccount $saveAccount): void
    {
        $account = $this->editingId !== null ? TreasuryAccount::query()->findOrFail($this->editingId) : null;

        $this->runAction(fn () => $saveAccount->handle($this->actor(), $account, $this->accountForm, $this->formKey), 'accountForm');

        $this->showAccountForm = false;
        $this->toast($account ? __('Changes saved.') : __('Account added.'));
        $this->refreshLists();
    }

    public function openTransfer(?int $fromId = null): void
    {
        $this->authorize(Permission::TransactionsRecord->value);
        $this->resetErrorBag();
        $this->transferForm = [
            'from_account_id' => $fromId !== null ? (string) $fromId : '',
            'to_account_id' => '',
            'amount' => '',
            'entry_date' => now()->format('Y-m-d'),
            'memo' => '',
        ];
        $this->formKey = (string) Str::uuid();
        $this->showTransferForm = true;
    }

    public function saveTransfer(RecordTransfer $recordTransfer): void
    {
        $entry = $this->runAction(fn () => $recordTransfer->handle($this->actor(), $this->transferForm, $this->formKey), 'transferForm');

        $this->showTransferForm = false;
        $this->toast(__('Transfer :number recorded.', ['number' => $entry->number]));
        $this->refreshLists();
    }

    public function openCapital(string $direction = RecordCapital::IN): void
    {
        $this->authorize(Permission::CapitalRecord->value);
        $this->resetErrorBag();
        $this->capitalForm = [
            'direction' => $direction === RecordCapital::OUT ? RecordCapital::OUT : RecordCapital::IN,
            'account_id' => '',
            'amount' => '',
            'entry_date' => now()->format('Y-m-d'),
            'memo' => '',
        ];
        $this->formKey = (string) Str::uuid();
        $this->showCapitalForm = true;
    }

    public function saveCapital(RecordCapital $recordCapital): void
    {
        $entry = $this->runAction(fn () => $recordCapital->handle($this->actor(), $this->capitalForm, $this->formKey), 'capitalForm');

        $this->showCapitalForm = false;
        $this->toast(__(':number recorded.', ['number' => $entry->number]));
        $this->refreshLists();
    }

    public function archive(int $id, SetTreasuryAccountArchived $setArchived): void
    {
        $this->setArchived($id, true, $setArchived);
    }

    public function restore(int $id, SetTreasuryAccountArchived $setArchived): void
    {
        $this->setArchived($id, false, $setArchived);
    }

    public function render(): View
    {
        $actor = $this->actor();

        return view('livewire.accounts.account-index', [
            'canManage' => $actor->can(Permission::AccountsManage->value),
            'canTransfer' => $actor->can(Permission::TransactionsRecord->value),
            'canCapital' => $actor->can(Permission::CapitalRecord->value),
        ])->title(__('Cash and bank'));
    }

    private function setArchived(int $id, bool $archived, SetTreasuryAccountArchived $setArchived): void
    {
        $account = TreasuryAccount::query()->findOrFail($id);

        try {
            $setArchived->handle($this->actor(), $account, $archived);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($archived ? __('Archived. It no longer appears in new entries.') : __('Restored.'));
        $this->refreshLists();
    }

    private function refreshLists(): void
    {
        unset($this->accounts, $this->balances, $this->netPosition, $this->accountOptions);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
