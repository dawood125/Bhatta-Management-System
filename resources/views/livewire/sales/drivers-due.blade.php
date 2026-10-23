<div>
    <x-ui.page-header :title="__('Sales')" :description="__('Carriage the customers paid is owed to the drivers. Pay them at the end of the day with a payment voucher.')" />

    <x-sales.tabs active="drivers" />

    <x-ui.card class="mb-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.field :label="__('Trips on')" for="drivers-date" class="sm:w-56">
                <x-ui.input id="drivers-date" type="date" wire:model.live="date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </x-ui.field>
            @if ($canPay && ($link = $this->payLink($this->rows)))
                <x-ui.button icon="hand-coins" :href="$link" wire:navigate>{{ __('Pay all drivers owed') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th class="min-w-44">{{ __('Driver') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Trips that day') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Carriage that day') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Owed now') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="driver-{{ $row['driver']->id }}" class="transition-colors hover:bg-stone-50/70" wire:loading.class="opacity-60" wire:target="date">
                <x-ui.td>
                    <a href="{{ route('parties.show', $row['driver']) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $row['driver']->displayName() }}</a>
                    @if ($row['driver']->phone)
                        <p class="text-xs text-stone-500" dir="ltr">{{ $row['driver']->phone }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td align="end" class="tabular">{{ number_format($row['trips']) }}</x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$row['carriage']" :currency="false" :muted="$row['carriage']->isZero()" /></x-ui.td>
                <x-ui.td align="end" class="font-semibold"><x-ui.money :amount="$row['owed']" :currency="false" :muted="$row['owed']->isZero()" /></x-ui.td>
                <x-ui.td align="end">
                    @if ($canPay && $row['owed']->isPositive())
                        <x-ui.button size="sm" variant="secondary" icon="hand-coins" :href="$this->payLink(collect([$row]))" wire:navigate>{{ __('Pay') }}</x-ui.button>
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty-state icon="truck" :title="__('No drivers due')" :description="__('Drivers appear here when they made a trip that day or are still owed carriage.')" />
                </td>
            </tr>
        @endforelse

        @if ($this->rows->isNotEmpty())
            <x-slot:footer>
                <div class="flex justify-between gap-3 text-sm font-semibold text-stone-900">
                    <span>{{ __('Total owed to drivers') }}</span>
                    <x-ui.money :amount="$totalOwed" />
                </div>
            </x-slot:footer>
        @endif
    </x-ui.table>
</div>
