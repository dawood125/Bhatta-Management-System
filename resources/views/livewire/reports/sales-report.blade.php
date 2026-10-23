<div>
    <x-reports.header :title="__('Sales summary')" :description="__('Sales made in a period (by sale date; cancelled sales left out), by grade, by customer and by sales agent.')"
        :period="$range ? $range[0]->format('d-m-Y').' – '.$range[1]->format('d-m-Y') : null" />

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="sales-report-range" />
    </x-ui.card>

    @php($report = $this->report)
    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" wire:loading.class="opacity-60" wire:target="from,to,setRange">
            @foreach ([
                [__('Sales'), number_format($report['count']), null],
                [__('Billed'), null, $report['billed']],
                [__('Paid'), null, $report['paid']],
                [__('Still to be paid'), null, $report['outstanding']],
            ] as [$label, $text, $amount])
                <x-ui.card class="py-4!">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-stone-900">@if ($amount)<x-ui.money :amount="$amount" />@else<span class="tabular">{{ $text }}</span>@endif</p>
                </x-ui.card>
            @endforeach
        </div>
        <p class="mb-4 text-xs text-stone-500">
            {{ __('Billed = bricks :bricks + carriage :carriage − bricks not delivered :credits.', ['bricks' => $report['bricks']->format(), 'carriage' => $report['carriage']->format(), 'credits' => $report['credits']->format()]) }}
        </p>

        <div class="space-y-4">
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th class="min-w-36">{{ __('Grade') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Ordered') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Delivered') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
                </x-slot:head>
                @forelse ($report['by_grade'] as $row)
                    <tr wire:key="grade-{{ $row['product']->id }}">
                        <x-ui.td class="font-medium text-stone-900">{{ $row['product']->displayName() }}</x-ui.td>
                        <x-ui.td align="end" class="tabular">{{ $row['ordered']->format($row['product']->unit->decimals) }} <span class="text-xs text-stone-500">{{ $row['product']->unit->symbol }}</span></x-ui.td>
                        <x-ui.td align="end" class="tabular">{{ $row['delivered']->format($row['product']->unit->decimals) }}</x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$row['amount']" :currency="false" /></x-ui.td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-ui.empty-state icon="shopping-cart" :title="__('No sales in this period')" /></td></tr>
                @endforelse
            </x-ui.table>

            <div class="grid gap-4 xl:grid-cols-2">
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th class="min-w-40">{{ __('Customer') }}</x-ui.th>
                        <x-ui.th align="end">{{ __('Sales') }}</x-ui.th>
                        <x-ui.th align="end">{{ __('Billed') }}</x-ui.th>
                        <x-ui.th align="end">{{ __('Still to pay') }}</x-ui.th>
                    </x-slot:head>
                    @foreach ($report['by_customer'] as $row)
                        <tr wire:key="customer-{{ $row['party']->id }}">
                            <x-ui.td><a href="{{ route('parties.show', $row['party']) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $row['party']->displayName() }}</a></x-ui.td>
                            <x-ui.td align="end" class="tabular">{{ number_format($row['count']) }}</x-ui.td>
                            <x-ui.td align="end"><x-ui.money :amount="$row['billed']" :currency="false" /></x-ui.td>
                            <x-ui.td align="end"><x-ui.money :amount="$row['outstanding']" :currency="false" :muted="$row['outstanding']->isZero()" /></x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th class="min-w-40">{{ __('Sales agent') }}</x-ui.th>
                        <x-ui.th align="end">{{ __('Sales') }}</x-ui.th>
                        <x-ui.th align="end">{{ __('Billed') }}</x-ui.th>
                    </x-slot:head>
                    @foreach ($report['by_agent'] as $row)
                        <tr wire:key="agent-{{ $row['agent']?->id ?? 0 }}">
                            <x-ui.td class="font-medium text-stone-900">{{ $row['agent']?->displayName() ?? __('No agent') }}</x-ui.td>
                            <x-ui.td align="end" class="tabular">{{ number_format($row['count']) }}</x-ui.td>
                            <x-ui.td align="end"><x-ui.money :amount="$row['billed']" :currency="false" /></x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        </div>
    @endif
</div>
