@php use App\Enums\StageKind; @endphp
<div>
    <x-reports.header :title="__('Production summary')" :description="__('Bricks made, fired, unloaded and delivered in a period, and the stock of every stage at its start and end. Reversed entries are left out of the work totals.')"
        :period="$range ? $range[0]->format('d-m-Y').' – '.$range[1]->format('d-m-Y') : null" />

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="production-report-range" />
    </x-ui.card>

    @php($report = $this->report)
    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" wire:loading.class="opacity-60" wire:target="from,to,setRange">
            @foreach ([
                [__('Pathai: made'), $report['pathai']['made'], __(':rejected rejected', ['rejected' => $report['pathai']['rejected']->format(0)])],
                [__('Pathai: accepted'), $report['pathai']['accepted'], null],
                [__('Loaded into the kiln'), $report['loaded'], null],
                [__('Firing complete'), $report['fired'], null],
                [__('Nakaasi (unloaded)'), $report['unloaded'], null],
            ] as [$label, $quantity, $hint])
                <x-ui.card class="py-4!">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-stone-900 tabular">{{ $quantity->format(0) }}</p>
                    @if ($hint)<p class="text-xs text-stone-500">{{ $hint }}</p>@endif
                </x-ui.card>
            @endforeach
        </div>

        <h2 class="mb-2 text-base font-semibold text-stone-900">{{ __('Stock by stage and grade') }}</h2>
        <x-ui.table class="mb-6">
            <x-slot:head>
                <x-ui.th class="min-w-48">{{ __('Stage') }}</x-ui.th>
                <x-ui.th align="end">{{ __('At the start') }}</x-ui.th>
                <x-ui.th align="end">{{ __('In') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Out') }}</x-ui.th>
                <x-ui.th align="end">{{ __('At the end') }}</x-ui.th>
            </x-slot:head>
            @foreach ($report['slots'] as $slot)
                @php($product = $slot['product_id'] ? $this->products->get($slot['product_id']) : null)
                @php($decimals = $product?->unit->decimals ?? 0)
                <tr wire:key="slot-{{ $slot['stage']->value }}-{{ $slot['product_id'] ?? 0 }}">
                    <x-ui.td>
                        @if ($product)
                            <span class="text-stone-500">{{ $this->stages->get(StageKind::Saleable->value)?->displayName() ?? StageKind::Saleable->defaultName() }}:</span> <span class="font-medium text-stone-900">{{ $product->displayName() }}</span>
                        @else
                            <span class="font-medium text-stone-900">{{ $this->stages->get($slot['stage']->value)?->displayName() ?? $slot['stage']->defaultName() }}</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end" class="tabular text-stone-600">{{ $slot['opening']->format($decimals) }}</x-ui.td>
                    <x-ui.td align="end" class="tabular">{{ $slot['in']->format($decimals) }}</x-ui.td>
                    <x-ui.td align="end" class="tabular">{{ $slot['out']->format($decimals) }}</x-ui.td>
                    <x-ui.td align="end" class="tabular font-semibold text-stone-900">{{ $slot['closing']->format($decimals) }}</x-ui.td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            @foreach ([[__('Nakaasi by grade'), $report['unloaded_by_product']], [__('Delivered to customers by grade'), $report['delivered_by_product']]] as [$heading, $byProduct])
                <x-ui.card :padding="false">
                    <h2 class="border-b border-stone-100 px-4 py-3 text-base font-semibold text-stone-900">{{ $heading }}</h2>
                    <dl class="divide-y divide-stone-100 text-sm">
                        @forelse ($byProduct as $productId => $quantity)
                            @php($product = $this->products->get($productId))
                            <div class="flex justify-between gap-3 px-4 py-2.5">
                                <dt class="text-stone-700">{{ $product?->displayName() }}</dt>
                                <dd class="tabular font-medium text-stone-900">{{ $quantity->format($product?->unit->decimals ?? 0) }} <span class="text-xs font-normal text-stone-500">{{ $product?->unit->symbol }}</span></dd>
                            </div>
                        @empty
                            <div class="px-4 py-2.5 text-stone-400">{{ __('None') }}</div>
                        @endforelse
                    </dl>
                </x-ui.card>
            @endforeach
        </div>

        <h2 class="mb-2 text-base font-semibold text-stone-900">{{ __('Day by day') }}</h2>
        <x-ui.table>
            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Made') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Accepted') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Loaded') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Fired') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Unloaded') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Delivered') }}</x-ui.th>
            </x-slot:head>
            @php($days = array_filter($report['days'], fn ($day) => collect($day)->except('date')->contains(fn ($q) => ! $q->isZero())))
            @forelse ($days as $day)
                <tr wire:key="day-{{ $day['date'] }}">
                    <x-ui.td class="whitespace-nowrap"><a href="{{ route('production.day', ['date' => $day['date']]) }}" wire:navigate class="tabular font-medium text-brand-700 hover:underline" dir="ltr">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d-m-Y') }}</a></x-ui.td>
                    @foreach (['made', 'accepted', 'loaded', 'fired', 'unloaded', 'delivered'] as $column)
                        <x-ui.td align="end" @class(['tabular', 'text-stone-300' => $day[$column]->isZero()])>{{ $day[$column]->format(0) }}</x-ui.td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="7"><x-ui.empty-state icon="factory" :title="__('No production in this period')" /></td></tr>
            @endforelse
        </x-ui.table>
        <p class="mt-2 text-xs text-stone-500">{{ __('Delivered counts the products counted in bricks.') }}</p>
    @endif
</div>
