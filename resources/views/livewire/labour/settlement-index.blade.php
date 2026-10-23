<div>
    <x-ui.page-header :title="__('Labour')" :description="__('Wage settlements: who was paid what, and what remained on their account.')">
        @if ($canPay)
            <x-slot:actions>
                <x-ui.button icon="hand-coins" :href="route('labour.settlements.create')" wire:navigate>{{ __('New settlement') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-labour.tabs active="settlements" />

    <x-ui.card class="mb-4">
        <x-date-range id="settlements-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-2xl">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Wages paid') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->totalPaid" /></p>
                <p class="text-xs text-stone-500">{{ __('Reversed payments are not counted.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:max-w-xs">
                    <label for="settlements-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="settlements-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number, worker or note')" />
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th>{{ __('Period') }}</x-ui.th>
                <x-ui.th>{{ __('Paid from') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Workers') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Paid') }}</x-ui.th>
            </x-slot:head>

            @forelse ($this->settlements as $settlement)
                <tr wire:key="settlement-{{ $settlement->id }}" @class(['transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $settlement->isReversed()]) wire:loading.class="opacity-60" wire:target="search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $settlement->settlement_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        <a href="{{ route('labour.settlements.show', $settlement) }}" wire:navigate class="tabular font-medium text-brand-700 hover:underline" dir="ltr">{{ $settlement->number }}</a>
                        @if ($settlement->isReversed())
                            <x-ui.badge tone="danger" icon="undo-2" class="ms-1">{{ __('Reversed') }}</x-ui.badge>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular text-stone-600" dir="ltr">{{ $settlement->period_from->format('d-m-Y') }} – {{ $settlement->period_to->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">{{ $settlement->treasuryAccount->displayName() }}</x-ui.td>
                    <x-ui.td align="end" class="tabular">{{ number_format($settlement->lines_count) }}</x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $settlement->isReversed()])><x-ui.money :amount="$settlement->total_paid" /></x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state icon="hand-coins" :title="__('No settlements in this period')" :description="$search !== '' ? __('Try a different search or filter.') : __('Settle wages weekly or monthly; each settlement keeps every worker\'s payslip.')">
                            @if ($canPay && $search === '')
                                <x-ui.button icon="hand-coins" variant="secondary" :href="route('labour.settlements.create')" wire:navigate>{{ __('New settlement') }}</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse

            @if ($this->settlements->hasPages())
                <x-slot:footer>{{ $this->settlements->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif
</div>
