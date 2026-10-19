<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Material cost report')" :description="__('What each material cost in the period: stock at the start, bought, used, lost, and stock at the end.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
        <x-materials.tabs active="report" />
    </div>

    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Material cost report') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="report-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <x-ui.table wire:loading.class="opacity-60" wire:target="from,to,setRange">
            <x-slot:head>
                <x-ui.th class="min-w-40">{{ __('Material') }}</x-ui.th>
                <x-ui.th align="end">{{ __('At the start') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Bought') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Used') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Shortage') }}</x-ui.th>
                <x-ui.th align="end">{{ __('At the end') }}</x-ui.th>
            </x-slot:head>

            @forelse ($this->rows as $row)
                @php($decimals = $row['material']->unit->decimals)
                @php($unit = $row['material']->unit->symbol ?? $row['material']->unit->displayName())
                <tr wire:key="report-{{ $row['material']->id }}" class="align-top">
                    <x-ui.td>
                        <a href="{{ route('materials.show', $row['material']) }}" wire:navigate class="font-medium text-stone-900 hover:underline print:no-underline">{{ $row['material']->displayName() }}</a>
                        <span class="block text-xs text-stone-500">{{ $row['material']->unit->displayName() }}</span>
                    </x-ui.td>
                    @foreach (['opening', 'bought', 'used', 'shortage', 'closing'] as $key)
                        <x-ui.td align="end" class="whitespace-nowrap">
                            <span @class(['tabular block', 'text-stone-400' => $row[$key]['quantity']->isZero(), 'text-stone-900' => ! $row[$key]['quantity']->isZero(), 'font-semibold' => $key === 'used']) dir="ltr">{{ $row[$key]['quantity']->format($decimals) }} <span class="text-xs font-normal text-stone-500">{{ $unit }}</span></span>
                            <x-ui.money :amount="$row[$key]['value']" :currency="false" :muted="true" class="text-xs text-stone-600" />
                        </x-ui.td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="package" :title="__('No materials yet')" :description="__('Add soil, coal and other materials in Kiln settings.')" /></td></tr>
            @endforelse

            @if ($totals)
                <tr class="bg-stone-50/80 font-semibold">
                    <x-ui.td>{{ __('Total value') }}</x-ui.td>
                    @foreach (['opening', 'bought', 'used', 'shortage', 'closing'] as $key)
                        <x-ui.td align="end"><x-ui.money :amount="$totals[$key]" /></x-ui.td>
                    @endforeach
                </tr>
            @endif
        </x-ui.table>
        <p class="mt-2 text-xs text-stone-500">{{ __('Values are at average cost and include transport, excavator and other charges. A reversal is counted on the day it was made, against what it reverses.') }}</p>
    @endif
</div>
