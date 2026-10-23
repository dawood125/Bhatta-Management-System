<div>
    <div class="mb-2">
        <a href="{{ route('vouchers.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Payments and receipts') }}
        </a>
    </div>

    <x-ui.page-header
        :title="$isPayment ? __('New payment') : __('New receipt')"
        :description="$isPayment
            ? __('Money paid from one account to one or more parties or other accounts.')
            : __('Money received into one account from one or more parties or other accounts.')"
    />

    {{-- The party list, sent once with the page (wire:ignore keeps it on later updates). --}}
    <div wire:ignore>
        @unless ($optionsSent)
            <script type="application/json" id="party-options">@json($this->partyOptions())</script>
        @endunless
    </div>

    <form wire:submit="save" class="space-y-4">
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.field :label="$isPayment ? __('Paid from') : __('Received into')" for="voucher-account" error="form.account_id" required>
                    <x-ui.select id="voucher-account" wire:model.live="form.account_id" :placeholder="__('Choose an account')" :options="$this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                    @if (isset($this->accountBalances[(int) $form['account_id']]))
                        <p class="text-xs text-stone-500">{{ __('Balance now') }}: <x-ui.money :amount="$this->accountBalances[(int) $form['account_id']]" class="font-medium text-stone-700" /></p>
                    @endif
                </x-ui.field>
                <x-ui.field :label="__('Date')" for="voucher-date" error="form.entry_date" required>
                    <x-ui.input id="voucher-date" type="date" wire:model="form.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Note')" for="voucher-memo" error="form.memo" :hint="__('Optional')">
                    <x-ui.input id="voucher-memo" wire:model="form.memo" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-stone-900">{{ $isPayment ? __('Paid to') : __('Received from') }}</h2>
                <span class="text-xs text-stone-500">{{ __(':count of :max lines', ['count' => count($form['lines']), 'max' => \App\Actions\Vouchers\RecordVoucher::MAX_LINES]) }}</span>
            </div>

            @error('form.lines')
                <p class="mb-3 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
            @enderror

            {{-- Column titles on wide screens; on phones every field has its own label. --}}
            <div class="hidden gap-3 border-b border-stone-100 pb-2 text-xs font-medium uppercase tracking-wide text-stone-500 lg:grid lg:grid-cols-[9rem_minmax(0,1fr)_10rem_minmax(0,0.8fr)_2.5rem]">
                <span>{{ __('Type') }}</span>
                <span>{{ __('Party or account') }}</span>
                <span class="text-end">{{ __('Amount') }}</span>
                <span>{{ __('Remarks') }}</span>
                <span class="sr-only">{{ __('Remove') }}</span>
            </div>

            <ol class="divide-y divide-stone-100">
                @foreach ($form['lines'] as $i => $line)
                    <li wire:key="line-{{ $line['key'] }}" class="grid gap-3 py-3 lg:grid-cols-[9rem_minmax(0,1fr)_10rem_minmax(0,0.8fr)_2.5rem] lg:items-start">
                        <div class="flex items-center justify-between gap-3 lg:block">
                            <label for="line-{{ $i }}-type" class="text-xs font-medium text-stone-500 lg:sr-only">{{ __('Line :number', ['number' => $i + 1]) }}</label>
                            <div class="w-40 lg:w-auto">
                                <x-ui.select id="line-{{ $i }}-type" wire:model.live="form.lines.{{ $i }}.type" :options="['party' => __('Party'), 'account' => __('Our account')]" />
                            </div>
                        </div>

                        <div class="min-w-0 space-y-1.5">
                            @if ($line['type'] === 'account')
                                <label for="line-{{ $i }}-account" class="sr-only">{{ __('Account') }}</label>
                                <x-ui.select id="line-{{ $i }}-account" wire:model.live="form.lines.{{ $i }}.account_id" :placeholder="__('Choose an account')"
                                    :options="$this->accounts->except((int) $form['account_id'])->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                                @error("form.lines.$i.account_id")
                                    <p id="line-{{ $i }}-account-error" class="text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            @else
                                <label for="line-{{ $i }}-party" class="sr-only">{{ __('Party') }}</label>
                                <x-ui.combobox id="line-{{ $i }}-party" source="party-options" wire:model.live="form.lines.{{ $i }}.party_id" :placeholder="__('Search a party by name or phone')" />
                                @error("form.lines.$i.party_id")
                                    <p id="line-{{ $i }}-party-error" class="text-sm text-red-600">{{ $message }}</p>
                                @else
                                    @if (ctype_digit((string) $line['party_id']) && isset($this->partyBalances[(int) $line['party_id']]))
                                        <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                                            <span>{{ __('Balance now') }}:</span>
                                            <x-party-balance :amount="$this->partyBalances[(int) $line['party_id']]" class="justify-start!" />
                                        </div>
                                    @endif
                                @enderror
                            @endif
                        </div>

                        <div class="space-y-1.5">
                            <label for="line-{{ $i }}-amount" class="text-xs font-medium text-stone-500 lg:sr-only">{{ __('Amount') }}</label>
                            <x-ui.input id="line-{{ $i }}-amount" wire:model.live.debounce.500ms="form.lines.{{ $i }}.amount" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" />
                            @error("form.lines.$i.amount")
                                <p id="line-{{ $i }}-amount-error" class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-1.5">
                            <label for="line-{{ $i }}-memo" class="text-xs font-medium text-stone-500 lg:sr-only">{{ __('Remarks') }}</label>
                            <x-ui.input id="line-{{ $i }}-memo" wire:model="form.lines.{{ $i }}.memo" :placeholder="__('Optional')" />
                            @error("form.lines.$i.memo")
                                <p id="line-{{ $i }}-memo-error" class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex justify-end lg:pt-1">
                            <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeLine({{ $i }})" :aria-label="__('Remove line :number', ['number' => $i + 1])" :title="__('Remove')" :disabled="count($form['lines']) === 1" />
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="mt-2">
                <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addLine" loading="addLine" :disabled="count($form['lines']) >= \App\Actions\Vouchers\RecordVoucher::MAX_LINES">{{ __('Add line') }}</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <dl class="grid gap-4 sm:grid-cols-2 sm:gap-8">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total') }}</dt>
                    <dd class="mt-1 text-2xl font-semibold text-stone-900">
                        @if ($this->total !== null)
                            <x-ui.money :amount="$this->total" />
                        @else
                            <span class="text-base text-red-600">{{ __('Check the amounts') }}</span>
                        @endif
                    </dd>
                </div>
                @if ($balanceAfter !== null)
                    @php($account = $this->accounts->get((int) $form['account_id']))
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __(':account after this voucher', ['account' => $account->displayName()]) }}</dt>
                        <dd @class(['mt-1 text-lg font-semibold', 'text-red-600' => $balanceAfter->isNegative(), 'text-stone-800' => ! $balanceAfter->isNegative()])>
                            <x-ui.money :amount="$balanceAfter" />
                        </dd>
                        @if ($balanceAfter->isNegative() && ! $account->allow_negative)
                            <p class="mt-1 flex items-start gap-1.5 text-xs text-red-600"><x-lucide-triangle-alert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> {{ __('Not enough money in this account.') }}</p>
                        @endif
                    </div>
                @endif
            </dl>
            <div class="flex shrink-0 items-center gap-2">
                <x-ui.button variant="secondary" :href="route('vouchers.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" loading="save">{{ $isPayment ? __('Save payment') : __('Save receipt') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
