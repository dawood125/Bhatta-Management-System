<div>
    <div class="mb-2">
        <a href="{{ route('labour.settlements') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Settlements') }}
        </a>
    </div>

    <x-ui.page-header :title="__('New settlement')" :description="__('Pay workers what they earned in the period, less advances and deductions. Whatever is not paid stays on their account.')" />

    <form wire:submit="save">
        <x-ui.card class="mb-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field :label="__('Period from')" for="settle-from" error="periodFrom" required>
                    <x-ui.input id="settle-from" type="date" wire:model.live="periodFrom" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Period to')" for="settle-to" error="periodTo" required>
                    <x-ui.input id="settle-to" type="date" wire:model.live="periodTo" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Payment date')" for="settle-date" error="settlementDate" required>
                    <x-ui.input id="settle-date" type="date" wire:model.live="settlementDate" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Workers')" for="settle-category">
                    <x-ui.select id="settle-category" wire:model.live="category" :placeholder="__('All categories')" :options="$this->categoryOptions" />
                </x-ui.field>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.button size="sm" variant="secondary" wire:click="setPeriod('last_7_days')">{{ __('Last 7 days') }}</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="setPeriod('this_month')">{{ __('This month') }}</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="setPeriod('last_month')">{{ __('Last month') }}</x-ui.button>
            </div>
        </x-ui.card>

        @error('lines')
            <p class="mb-4 flex items-start gap-1.5 rounded-[var(--radius-control)] bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
        @enderror

        @if ($this->rows === null)
            <x-ui.card :padding="false">
                <x-ui.empty-state icon="calendar" :title="__('Choose a valid period')" :description="__('The period must end on or before the payment date.')" />
            </x-ui.card>
        @else
            <x-ui.table wire:loading.class="opacity-60" wire:target="periodFrom,periodTo,settlementDate,category,setPeriod">
                <x-slot:head>
                    <x-ui.th class="w-10"><span class="sr-only">{{ __('Include') }}</span></x-ui.th>
                    <x-ui.th class="min-w-48">{{ __('Worker') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Brought forward') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Earned') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Advances') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Deductions') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Payable') }}</x-ui.th>
                    <x-ui.th align="end" class="min-w-40">{{ __('Pay now') }}</x-ui.th>
                </x-slot:head>

                @forelse ($this->rows as $row)
                    @php($id = $row['worker']->id)
                    @php($included = $include[$id] ?? false)
                    <tr wire:key="settle-{{ $id }}" @class(['align-top', 'opacity-50' => ! $included])>
                        <x-ui.td>
                            <input type="checkbox" id="include-{{ $id }}" wire:model.live="include.{{ $id }}" class="mt-1 size-4 accent-brand-600" aria-label="{{ __('Include :name', ['name' => $row['worker']->displayName()]) }}">
                        </x-ui.td>
                        <x-ui.td>
                            <label for="include-{{ $id }}" class="font-medium text-stone-900">{{ $row['worker']->displayName() }}</label>
                            <p class="text-xs text-stone-500">{{ $row['worker']->category->displayName() }}</p>
                            @if (! $row['owed_now']->equals($row['summary']['payable']))
                                <p class="text-xs text-stone-500">{{ __('Owed on the payment date') }}: <x-ui.money :amount="$row['owed_now']" /></p>
                            @endif
                        </x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$row['summary']['brought_forward']" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$row['summary']['earned']" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$row['summary']['advances']" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$row['summary']['deductions']" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end"><x-worker-balance :amount="$row['summary']['payable']" /></x-ui.td>
                        <x-ui.td align="end">
                            <label for="pay-{{ $id }}" class="sr-only">{{ __('Pay :name now', ['name' => $row['worker']->displayName()]) }}</label>
                            <x-ui.input id="pay-{{ $id }}" wire:model.live.debounce.500ms="pay.{{ $id }}" inputmode="decimal" dir="ltr" :suffix="__('Rs')" :disabled="! $included" autocomplete="off" />
                            @error('pay.'.$id)
                                <p id="pay-{{ $id }}-error" class="mt-1 text-start text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="hard-hat" :title="__('No active workers')" :description="__('Add workers first, or choose another category.')" /></td></tr>
                @endforelse
            </x-ui.table>

            <x-ui.card class="mt-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.field :label="__('Paid from')" for="settle-account" error="accountId" required>
                        <x-ui.select id="settle-account" wire:model.live="accountId" :placeholder="__('Choose an account')" :options="$this->accountOptions" />
                        @if ($this->accountBalance !== null)
                            <p class="text-xs text-stone-500">{{ __('Balance now') }}: <x-ui.money :amount="$this->accountBalance" class="font-medium text-stone-700" /></p>
                        @endif
                    </x-ui.field>
                    <x-ui.field :label="__('Note')" for="settle-notes" error="notes" :hint="__('Optional')">
                        <x-ui.input id="settle-notes" wire:model="notes" />
                    </x-ui.field>
                    <div class="flex flex-col justify-end gap-3 sm:col-span-2 lg:col-span-1 lg:items-end">
                        <p class="text-sm text-stone-600">{{ __('Total to pay') }}: <x-ui.money :amount="$total" class="text-lg font-semibold text-stone-900" /></p>
                        <x-ui.button type="submit" icon="check" loading="save">{{ __('Save settlement') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @endif
    </form>
</div>
