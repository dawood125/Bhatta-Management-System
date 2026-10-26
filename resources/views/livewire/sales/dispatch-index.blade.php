<div>
    <x-ui.page-header :title="__('Sales')" :description="__('Every trip that took bricks to a customer, with the vehicle, driver and carriage.')" />

    <x-sales.tabs active="deliveries" />

    <x-ui.card class="mb-4">
        <x-date-range id="deliveries-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Trips') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900 tabular">{{ number_format($this->summary['trips']) }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Bricks delivered') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900 tabular">{{ $this->summary['bricks']->format(0) }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Carriage') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->summary['carriage']" /></p>
                <p class="text-xs text-stone-500">{{ __('Owed to the drivers; not kiln income.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:max-w-xs">
                    <label for="deliveries-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="deliveries-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number, customer, vehicle or driver')" />
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-44">{{ __('Customer') }}</x-ui.th>
                <x-ui.th class="min-w-40">{{ __('Bricks') }}</x-ui.th>
                <x-ui.th>{{ __('Vehicle and driver') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Carriage') }}</x-ui.th>
            </x-slot:head>

            @forelse ($this->dispatches as $dispatch)
                <tr wire:key="dispatch-{{ $dispatch->id }}" @class(['transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $dispatch->isReversed()]) wire:loading.class="opacity-60" wire:target="search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $dispatch->dispatch_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        <span class="tabular font-medium text-stone-900" dir="ltr">{{ $dispatch->number }}</span>
                        <a href="{{ route('sales.show', $dispatch->sale) }}" wire:navigate class="block text-xs text-brand-700 hover:underline" dir="ltr">{{ $dispatch->sale->number }}</a>
                        @if ($dispatch->isReversed())
                            <x-ui.badge tone="danger" icon="undo-2" class="mt-1">{{ __('Reversed') }}</x-ui.badge>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="font-medium text-stone-900">{{ $dispatch->sale->customer->displayName() }}</x-ui.td>
                    <x-ui.td class="text-sm text-stone-600">
                        @foreach ($dispatch->lines as $dl)
                            @php($line = $dispatch->sale->lines->firstWhere('id', $dl->sale_line_id))
                            <span class="whitespace-nowrap">{{ $line?->product->displayName() }} <bdi class="tabular">{{ $dl->quantity->format($line?->product->unit->decimals ?? 0) }}</bdi></span>@if (! $loop->last)<span class="text-stone-300"> · </span>@endif
                        @endforeach
                    </x-ui.td>
                    <x-ui.td class="text-sm text-stone-600">{{ collect([$dispatch->vehicle, $dispatch->driver?->displayName()])->filter()->implode(' · ') ?: __('Customer\'s own transport') }}</x-ui.td>
                    <x-ui.td align="end" @class(['line-through decoration-stone-400' => $dispatch->isReversed()])>
                        @if ($dispatch->carriage->isPositive())
                            <x-ui.money :amount="$dispatch->carriage" :currency="false" />
                        @else
                            <span class="text-stone-400">—</span>
                        @endif
                    </x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state icon="truck" :title="__('No deliveries in this period')" :description="$search !== '' ? __('Try a different search or filter.') : __('Deliveries are recorded from each sale, or with the sale when the bricks are taken at once.')" />
                    </td>
                </tr>
            @endforelse

            @if ($this->dispatches->hasPages())
                <x-slot:footer>{{ $this->dispatches->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif
</div>
