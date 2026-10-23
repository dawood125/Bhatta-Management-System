<div>
    <x-ui.page-header :title="__('Cash and bank')" :description="__('Where the kiln\'s money is kept. Balances come straight from the recorded transactions.')">
        <x-slot:actions>
            @if ($canCapital)
                <x-ui.button variant="secondary" icon="piggy-bank" wire:click="openCapital('in')">{{ __('Owner capital') }}</x-ui.button>
            @endif
            @if ($canTransfer)
                <x-ui.button variant="secondary" icon="arrow-left-right" wire:click="openTransfer">{{ __('Transfer') }}</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button icon="plus" wire:click="createAccount">{{ __('Add account') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        @unless ($showArchived)
            <x-ui.card class="flex items-center gap-4 sm:min-w-72">
                <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-lucide-wallet class="size-6" aria-hidden="true" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total money available') }}</p>
                    <p class="text-xl font-semibold text-stone-900"><x-ui.money :amount="$this->netPosition" /></p>
                </div>
            </x-ui.card>
        @else
            <div></div>
        @endunless

        <x-ui.checkbox id="show-archived" wire:model.live="showArchived" :label="__('Show archived accounts')" />
    </div>

    @if ($this->accounts->isEmpty())
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="landmark" :title="$showArchived ? __('No archived accounts') : __('No accounts yet')" :description="$showArchived ? null : __('Add your cash in hand and bank accounts with their current balances to start.')">
                @if (! $showArchived && $canManage)
                    <x-ui.button icon="plus" wire:click="createAccount">{{ __('Add account') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" wire:loading.class="opacity-60" wire:target="showArchived">
            @foreach ($this->accounts as $account)
                @php($balance = $this->balances[$account->ledger_account_id])
                <x-ui.card wire:key="account-{{ $account->id }}" class="flex flex-col">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-600">
                            <x-dynamic-component :component="'lucide-'.$account->kind->icon()" class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <x-settings.name-cell :record="$account" />
                            <p class="mt-0.5 text-xs text-stone-500">
                                {{ $account->kind->label() }}
                                @if ($account->account_number)
                                    · <bdi class="tabular whitespace-nowrap" dir="ltr">{{ $account->account_number }}</bdi>
                                @endif
                            </p>
                        </div>
                        @if ($canManage)
                            <div class="-me-2 -mt-1 flex shrink-0 items-center">
                                <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="editAccount({{ $account->id }})" :aria-label="__('Edit :name', ['name' => $account->displayName()])" :title="__('Edit')" />
                                @if ($account->is_active)
                                    <x-ui.button size="sm" variant="ghost" icon="archive" wire:click="archive({{ $account->id }})" wire:confirm="{{ __('Archive :name? It will no longer appear in new entries. Old records stay unchanged.', ['name' => $account->displayName()]) }}" :aria-label="__('Archive :name', ['name' => $account->displayName()])" :title="__('Archive')" />
                                @else
                                    <x-ui.button size="sm" variant="ghost" icon="archive-restore" wire:click="restore({{ $account->id }})" :aria-label="__('Restore :name', ['name' => $account->displayName()])" :title="__('Restore')" />
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="mt-5 flex-1">
                        <p class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-stone-500">
                            {{ $account->kind === \App\Enums\TreasuryKind::CreditCard ? __('Amount owed') : __('Balance') }}
                            @if ($account->allow_negative)
                                <x-ui.badge tone="warning" :title="__('This account may go below zero')">{{ __('Overdraft') }}</x-ui.badge>
                            @endif
                        </p>
                        <p class="text-2xl font-semibold text-stone-900"><x-ui.money :amount="$balance" /></p>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-1 border-t border-stone-100 pt-3">
                        <x-ui.button size="sm" variant="ghost" icon="book-open" :href="route('accounts.book', $account)" wire:navigate>{{ __('Cash book') }}</x-ui.button>
                        @if ($canTransfer && $account->is_active)
                            <x-ui.button size="sm" variant="ghost" icon="arrow-left-right" wire:click="openTransfer({{ $account->id }})">{{ __('Transfer') }}</x-ui.button>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    {{-- Add / edit account --}}
    @if ($canManage)
    <x-ui.modal wire:model="showAccountForm" :title="$editingId ? __('Edit account') : __('Add account')">
        <form id="account-form" wire:submit="saveAccount" class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Type')" for="account-kind" error="accountForm.kind" required class="sm:col-span-2" :hint="$editingId ? __('The type cannot be changed after the account is created.') : null">
                <x-ui.select id="account-kind" wire:model.live="accountForm.kind" :options="\App\Enums\TreasuryKind::options()" :disabled="$editingId !== null" />
            </x-ui.field>

            <x-settings.name-fields prefix="accountForm" id="account" />

            <x-ui.field :label="__('Account number')" for="account-number" error="accountForm.account_number" :hint="__('Optional')">
                <x-ui.input id="account-number" wire:model="accountForm.account_number" dir="ltr" />
            </x-ui.field>

            <x-ui.field :label="__('Opening date')" for="account-opening-date" error="accountForm.opening_date" required :hint="__('The day the balance below was counted.')">
                <x-ui.input id="account-opening-date" type="date" wire:model="accountForm.opening_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </x-ui.field>

            <x-ui.field :label="($accountForm['kind'] ?? '') === 'credit_card' ? __('Amount owed on opening date') : __('Balance on opening date')" for="account-opening" error="accountForm.opening_balance" required class="sm:col-span-2" :hint="$editingId ? __('Changing it reverses the old opening entry and records a new one; both stay in the history.') : __('Use 0 if the account starts empty.')">
                <x-ui.input id="account-opening" wire:model="accountForm.opening_balance" inputmode="decimal" :suffix="__('Rs')" dir="ltr" />
            </x-ui.field>

            <div class="sm:col-span-2">
                <x-ui.checkbox id="account-allow-negative" wire:model="accountForm.allow_negative" :label="__('Allow negative balance')" :description="__('Only for an overdraft facility. Otherwise the system stops payments when the money is not there.')" />
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="account-form" icon="check" loading="saveAccount">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif

    {{-- Transfer --}}
    @if ($canTransfer)
    <x-ui.modal wire:model="showTransferForm" :title="__('Transfer between accounts')" :description="__('Move money from one of the kiln\'s accounts to another, e.g. bank to cash in hand.')">
        <form id="transfer-form" wire:submit="saveTransfer" class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('From account')" for="transfer-from" error="transferForm.from_account_id" required>
                <x-ui.select id="transfer-from" wire:model="transferForm.from_account_id" :placeholder="__('Choose an account')" :options="$this->accountOptions" />
            </x-ui.field>
            <x-ui.field :label="__('To account')" for="transfer-to" error="transferForm.to_account_id" required>
                <x-ui.select id="transfer-to" wire:model="transferForm.to_account_id" :placeholder="__('Choose an account')" :options="$this->accountOptions" />
            </x-ui.field>
            <x-ui.field :label="__('Amount')" for="transfer-amount" error="transferForm.amount" required>
                <x-ui.input id="transfer-amount" wire:model="transferForm.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" />
            </x-ui.field>
            <x-ui.field :label="__('Date')" for="transfer-date" error="transferForm.entry_date" required>
                <x-ui.input id="transfer-date" type="date" wire:model="transferForm.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Note')" for="transfer-memo" error="transferForm.memo" :hint="__('Optional')" class="sm:col-span-2">
                <x-ui.input id="transfer-memo" wire:model="transferForm.memo" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="transfer-form" icon="arrow-left-right" loading="saveTransfer">{{ __('Record transfer') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif

    {{-- Owner capital in / withdrawal --}}
    @if ($canCapital)
    <x-ui.modal wire:model="showCapitalForm" :title="__('Owner capital')" :description="__('Money the owner puts into the kiln, or takes out for personal use.')">
        <form id="capital-form" wire:submit="saveCapital" class="grid gap-4 sm:grid-cols-2">
            <fieldset class="sm:col-span-2">
                <legend class="mb-1.5 text-sm font-medium text-stone-700">{{ __('Direction') }}</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['in' => [__('Money in (capital)'), 'arrow-down-left'], 'out' => [__('Money out (withdrawal)'), 'arrow-up-right']] as $value => [$label, $icon])
                        <label @class([
                            'flex cursor-pointer items-center gap-2 rounded-[var(--radius-control)] border px-3 py-2.5 text-sm font-medium transition',
                            'border-brand-500 bg-brand-50 text-brand-800' => ($capitalForm['direction'] ?? '') === $value,
                            'border-stone-300 text-stone-600 hover:border-stone-400' => ($capitalForm['direction'] ?? '') !== $value,
                        ])>
                            <input type="radio" value="{{ $value }}" wire:model.live="capitalForm.direction" class="sr-only">
                            <x-dynamic-component :component="'lucide-'.$icon" class="size-4" aria-hidden="true" />
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field :label="__('Account')" for="capital-account" error="capitalForm.account_id" required class="sm:col-span-2">
                <x-ui.select id="capital-account" wire:model="capitalForm.account_id" :placeholder="__('Choose an account')" :options="$this->accountOptions" />
            </x-ui.field>
            <x-ui.field :label="__('Amount')" for="capital-amount" error="capitalForm.amount" required>
                <x-ui.input id="capital-amount" wire:model="capitalForm.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" />
            </x-ui.field>
            <x-ui.field :label="__('Date')" for="capital-date" error="capitalForm.entry_date" required>
                <x-ui.input id="capital-date" type="date" wire:model="capitalForm.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Note')" for="capital-memo" error="capitalForm.memo" :hint="__('Optional')" class="sm:col-span-2">
                <x-ui.input id="capital-memo" wire:model="capitalForm.memo" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="capital-form" icon="check" loading="saveCapital">{{ __('Save entry') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif
</div>
