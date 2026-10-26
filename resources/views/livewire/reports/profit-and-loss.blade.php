<div>
    <x-reports.header :title="__('Profit and loss')" :description="__('Income and costs of a period from the books. Materials, fuel and labour count when they are used; bricks in stock are not valued.')"
        :period="$range ? $range[0]->format('d-m-Y').' – '.$range[1]->format('d-m-Y') : null" :pdf="$pdfUrl" />

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="pnl-range" />
    </x-ui.card>

    @php($report = $this->report)
    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Income') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$report['total_income']" /></p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Costs') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$report['total_expenses']" /></p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $report['net']->isNegative() ? __('Loss') : __('Profit') }}</p>
                <p @class(['mt-1 text-lg font-semibold', 'text-emerald-700' => ! $report['net']->isNegative(), 'text-red-700' => $report['net']->isNegative()])><x-ui.money :amount="$report['net']->abs()" /></p>
            </x-ui.card>
        </div>

        <x-ui.table wire:loading.class="opacity-60" wire:target="from,to,setRange">
            <x-slot:head>
                <x-ui.th class="min-w-56">{{ __('Account') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
            </x-slot:head>

            <tr class="bg-stone-50/80"><x-ui.td colspan="2" class="font-semibold text-stone-900">{{ __('Income') }}</x-ui.td></tr>
            @forelse ($report['income'] as $row)
                <tr wire:key="pnl-in-{{ $row['account']->id }}">
                    <x-ui.td class="ps-8"><span class="tabular text-xs text-stone-400" dir="ltr">{{ $row['account']->code }}</span> {{ $row['account']->displayName() }}</x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$row['amount']" :currency="false" /></x-ui.td>
                </tr>
            @empty
                <tr><x-ui.td colspan="2" class="ps-8 text-stone-400">{{ __('No income in this period') }}</x-ui.td></tr>
            @endforelse
            <tr class="font-semibold"><x-ui.td>{{ __('Total income') }}</x-ui.td><x-ui.td align="end"><x-ui.money :amount="$report['total_income']" :currency="false" /></x-ui.td></tr>

            @foreach ($report['groups'] as $group)
                <tr class="bg-stone-50/80"><x-ui.td colspan="2" class="font-semibold text-stone-900">{{ $group['label'] }}</x-ui.td></tr>
                @foreach ($group['rows'] as $row)
                    <tr wire:key="pnl-{{ $group['key'] }}-{{ $row['account']->id }}">
                        <x-ui.td class="ps-8"><span class="tabular text-xs text-stone-400" dir="ltr">{{ $row['account']->code }}</span> {{ $row['account']->displayName() }}</x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$row['amount']" :currency="false" /></x-ui.td>
                    </tr>
                @endforeach
                <tr class="font-medium"><x-ui.td class="text-stone-600">{{ __('Total :group', ['group' => mb_strtolower($group['label'])]) }}</x-ui.td><x-ui.td align="end"><x-ui.money :amount="$group['total']" :currency="false" /></x-ui.td></tr>
            @endforeach

            <tr class="font-semibold"><x-ui.td>{{ __('Total costs') }}</x-ui.td><x-ui.td align="end"><x-ui.money :amount="$report['total_expenses']" :currency="false" /></x-ui.td></tr>
            <tr class="bg-stone-50/80 text-base font-semibold">
                <x-ui.td>{{ $report['net']->isNegative() ? __('Loss') : __('Profit') }}</x-ui.td>
                <x-ui.td align="end" @class(['text-emerald-700' => ! $report['net']->isNegative(), 'text-red-700' => $report['net']->isNegative()])><x-ui.money :amount="$report['net']->abs()" :currency="false" /></x-ui.td>
            </tr>
        </x-ui.table>
        <p class="mt-3 text-xs text-stone-500">{{ __('Carriage collected for drivers is not income, and paying them is not a cost. Money owed by or to parties and workers is on the balance sheet.') }}</p>
    @endif
</div>
