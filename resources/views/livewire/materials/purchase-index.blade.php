<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Material purchases')" :description="__('Soil, coal and other materials bought, with transport and other charges.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
                @if ($canRecord)
                    <x-ui.button icon="truck" :href="route('materials.purchases.create')" wire:navigate>{{ __('New purchase') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
        <x-materials.tabs active="purchases" />
    </div>

    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Material purchases') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="purchases-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:max-w-3xl">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Goods') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->totals['goods']" /></p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Charges') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->totals['charges']" /></p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total cost') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->totals['total']" /></p>
                <p class="text-xs text-stone-500">{{ __('Entries: :count', ['count' => number_format($this->totals['count'])]) }} · {{ __('Reversed entries are not counted.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:max-w-xs print:hidden">
                    <label for="purchases-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="purchases-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number, supplier or bill')" />
                </div>
                <div class="w-full sm:w-56 print:hidden">
                    <label for="purchases-material" class="sr-only">{{ __('Material') }}</label>
                    <x-ui.select id="purchases-material" wire:model.live="material" :placeholder="__('All materials')" :options="$this->materialOptions" />
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-56">{{ __('Supplier') }}</x-ui.th>
                <x-ui.th class="min-w-56">{{ __('Materials') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Total cost') }}</x-ui.th>
                <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
            </x-slot:head>

            @forelse ($this->purchases as $purchase)
                <tr wire:key="purchase-{{ $purchase->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $purchase->isReversed()]) wire:loading.class="opacity-60" wire:target="material,search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $purchase->purchase_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        <a href="{{ route('materials.purchases.show', $purchase) }}" wire:navigate class="tabular font-medium text-stone-800 hover:underline" dir="ltr">{{ $purchase->entry->number }}</a>
                    </x-ui.td>
                    <x-ui.td>
                        <bdi class="text-stone-900">{{ $purchase->supplier?->displayName() ?? __('No supplier') }}</bdi>
                        <p class="text-xs text-stone-500">
                            {{ $purchase->payment === \App\Models\MaterialPurchase::PAID_NOW ? __('Paid from :account', ['account' => $purchase->treasuryAccount?->displayName()]) : __('On credit') }}
                            @if (filled($purchase->reference))
                                · <bdi>{{ $purchase->reference }}</bdi>
                            @endif
                        </p>
                        @if ($purchase->isReversed())
                            <x-ui.badge tone="danger" icon="undo-2" class="mt-1">{{ __('Reversed') }}</x-ui.badge>
                        @endif
                    </x-ui.td>
                    <x-ui.td>
                        <ul class="space-y-0.5 text-stone-700">
                            @foreach ($purchase->lines as $line)
                                <li>{{ $line->material->displayName() }} <span class="tabular text-stone-500" dir="ltr">{{ $line->quantity->format($line->material->unit->decimals) }}</span> <span class="text-xs text-stone-500">{{ $line->material->unit->symbol }}</span></li>
                            @endforeach
                        </ul>
                        @if ($purchase->charges_total->isPositive())
                            <p class="text-xs text-stone-500">{{ __('Charges') }}: <x-ui.money :amount="$purchase->charges_total" /></p>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $purchase->isReversed()])><x-ui.money :amount="$purchase->total" /></x-ui.td>
                    <x-ui.td align="end" class="print:hidden">
                        <div class="flex items-center justify-end gap-1">
                            <x-ui.button size="sm" variant="ghost" icon="eye" :href="route('materials.purchases.show', $purchase)" wire:navigate :aria-label="__('Open :number', ['number' => $purchase->entry->number])" :title="__('Open')" />
                            @if ($canReverse && ! $purchase->isReversed())
                                <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $purchase->journal_entry_id }})" :aria-label="__('Reverse :number', ['number' => $purchase->entry->number])" :title="__('Reverse')" />
                            @endif
                        </div>
                    </x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state icon="truck" :title="__('No purchases in this period')" :description="$search !== '' || $material !== '' ? __('Try a different search or filter.') : __('Record soil, coal and other materials bought for the kiln.')">
                            @if ($canRecord && $search === '' && $material === '')
                                <x-ui.button icon="truck" variant="secondary" :href="route('materials.purchases.create')" wire:navigate>{{ __('New purchase') }}</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse

            @if ($this->purchases->hasPages())
                <x-slot:footer>{{ $this->purchases->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif

    <x-materials.reverse-modal :number="$this->reversingNumber()" />
</div>
