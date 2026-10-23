<div>
    <x-reports.header :title="__('Cost per 1,000 bricks')" :description="__('The costs of a period divided by the bricks that came out of the kiln in it, next to what 1,000 bricks sold for. A whole month or more gives the most useful figure.')"
        :period="$range ? $range[0]->format('d-m-Y').' – '.$range[1]->format('d-m-Y') : null" />

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="cost-report-range" />
    </x-ui.card>

    @php($report = $this->report)
    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3" wire:loading.class="opacity-60" wire:target="from,to,setRange">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Cost of 1,000 bricks') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900">@if ($report['total_per_thousand'])<x-ui.money :amount="$report['total_per_thousand']" />@else<span class="text-stone-400">—</span>@endif</p>
                <p class="text-xs text-stone-500">{{ __(':bricks bricks out of the kiln', ['bricks' => $report['unloaded']->format(0)]) }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('1,000 bricks sold for') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900">@if ($report['sales_per_thousand'])<x-ui.money :amount="$report['sales_per_thousand']" />@else<span class="text-stone-400">—</span>@endif</p>
                <p class="text-xs text-stone-500">{{ __(':bricks bricks delivered', ['bricks' => $report['delivered']->format(0)]) }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Margin per 1,000') }}</p>
                @if ($report['margin_per_thousand'])
                    <p @class(['mt-1 text-lg font-semibold', 'text-emerald-700' => ! $report['margin_per_thousand']->isNegative(), 'text-red-700' => $report['margin_per_thousand']->isNegative()])><x-ui.money :amount="$report['margin_per_thousand']" /></p>
                @else
                    <p class="mt-1 text-lg font-semibold text-stone-400">—</p>
                @endif
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th class="min-w-48">{{ __('Cost') }}</x-ui.th>
                <x-ui.th align="end">{{ __('In the period') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Per 1,000 bricks') }}</x-ui.th>
            </x-slot:head>
            @foreach ($report['costs'] as $row)
                <tr wire:key="cost-{{ $row['key'] }}">
                    <x-ui.td class="font-medium text-stone-900">{{ $row['label'] }}</x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$row['amount']" :currency="false" /></x-ui.td>
                    <x-ui.td align="end">@if ($row['per_thousand'])<x-ui.money :amount="$row['per_thousand']" :currency="false" />@else<span class="text-stone-400">—</span>@endif</x-ui.td>
                </tr>
            @endforeach
            <tr class="bg-stone-50/80 font-semibold">
                <x-ui.td>{{ __('Total') }}</x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$report['total']" :currency="false" /></x-ui.td>
                <x-ui.td align="end">@if ($report['total_per_thousand'])<x-ui.money :amount="$report['total_per_thousand']" :currency="false" />@else<span class="text-stone-400">—</span>@endif</x-ui.td>
            </tr>
        </x-ui.table>
        <p class="mt-3 text-xs text-stone-500">{{ __('Costs of a period also go into bricks that are still raw or in the kiln, so a single day can look high or low. Brick sales: :amount.', ['amount' => $report['sales']->formatWithCurrency()]) }}</p>
    @endif
</div>
