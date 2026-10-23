<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Production entries')" :description="__('Every Pathai, loading, firing, Nakaasi and adjustment. Mistakes are reversed, never deleted.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
        <x-production.tabs active="entries" />
        <x-production.actions class="mb-4" />
    </div>

    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Production entries') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="production-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:max-w-xs print:hidden">
                    <label for="production-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="production-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number, team or note')" />
                </div>
                <div class="w-full sm:w-56 print:hidden">
                    <label for="production-kind" class="sr-only">{{ __('Type') }}</label>
                    <x-ui.select id="production-kind" wire:model.live="kind" :options="$this->kindOptions()" />
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-72">{{ __('Details') }}</x-ui.th>
                <x-ui.th>{{ __('By') }}</x-ui.th>
                @can(\App\Enums\Permission::ProductionAdjust->value)
                    <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                @endcan
            </x-slot:head>

            @forelse ($this->entries as $entry)
                <tr wire:key="entry-{{ $entry->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $entry->isReversed()]) wire:loading.class="opacity-60" wire:target="kind,search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap">
                        <a href="{{ route('production.day', ['date' => $entry->entry_date->format('Y-m-d')]) }}" wire:navigate class="tabular hover:underline" dir="ltr">{{ $entry->entry_date->format('d-m-Y') }}</a>
                    </x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular font-medium text-stone-800" dir="ltr">{{ $entry->number }}</span></x-ui.td>
                    <x-ui.td><x-production.entry-summary :entry="$entry" :stages="$this->stages" :duplicate="in_array($entry->id, $this->duplicates, true)" /></x-ui.td>
                    <x-ui.td class="whitespace-nowrap text-stone-600">{{ $entry->creator?->name ?? __('System') }}</x-ui.td>
                    @can(\App\Enums\Permission::ProductionAdjust->value)
                        <x-ui.td align="end" class="print:hidden">
                            @unless ($entry->isReversed())
                                <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $entry->id }})" :aria-label="__('Reverse :number', ['number' => $entry->number])" :title="__('Reverse')" />
                            @endunless
                        </x-ui.td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="5"><x-ui.empty-state icon="factory" :title="__('No entries in this period')" :description="$search !== '' || $kind !== '' ? __('Try a different search or filter.') : __('Record Pathai, loading, firing and Nakaasi with the buttons above.')" /></td></tr>
            @endforelse

            @if ($this->entries->hasPages())
                <x-slot:footer>{{ $this->entries->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif

    <livewire:production.entry-forms />
    <x-production.reverse-modal :number="$this->reversingNumber()" />
</div>
