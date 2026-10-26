@php use App\Enums\ProductionEntryKind; use App\Enums\StageKind; use App\Models\Unit; @endphp
<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Daily production')" :description="__('What happened at each stage on one day.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
        <x-production.tabs active="day" />
        <x-production.actions class="mb-4" />
    </div>

    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Daily production') }}</h1>
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.button variant="secondary" icon="chevron-left" wire:click="previousDay" :aria-label="__('Previous day')" class="rtl:[&>svg]:rotate-180" />
            <div class="w-44">
                <label for="day-date" class="mb-1 block text-xs font-medium text-stone-500">{{ __('Date') }}</label>
                <x-ui.input id="day-date" type="date" wire:model.live="date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </div>
            <x-ui.button variant="secondary" icon="chevron-right" wire:click="nextDay" :aria-label="__('Next day')" class="rtl:[&>svg]:rotate-180" />
        </div>
    </x-ui.card>

    @php($report = $this->report)
    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date')" />
        </x-ui.card>
    @else
        <p class="mb-3 text-sm font-medium text-stone-700"><span class="tabular" dir="ltr">{{ \Illuminate\Support\Carbon::parse($date)->format('d-m-Y') }}</span></p>

        <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:max-w-3xl">
            @foreach (['made' => __('Bricks made'), 'rejected' => __('Rejected'), 'accepted' => __('Accepted')] as $key => $label)
                <x-ui.card class="py-4!">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Pathai') }} · {{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-stone-900 tabular" dir="ltr">{{ $report['pathai'][$key]->format(0) }}</p>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.table class="mb-6" wire:loading.class="opacity-60" wire:target="date,previousDay,nextDay">
            <x-slot:head>
                <x-ui.th class="min-w-48">{{ __('Stage') }}</x-ui.th>
                <x-ui.th align="end">{{ __('At the start of the day') }}</x-ui.th>
                <x-ui.th align="end">{{ __('In') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Out') }}</x-ui.th>
                <x-ui.th align="end">{{ __('At the end of the day') }}</x-ui.th>
            </x-slot:head>

            @foreach ($report['slots'] as $slot)
                @php($product = $slot['product_id'] !== null ? $this->products->get($slot['product_id']) : null)
                @php($decimals = $product?->unit->decimals ?? 0)
                <tr wire:key="slot-{{ $slot['stage']->value }}-{{ $slot['product_id'] ?? 0 }}">
                    <x-ui.td>
                        <span class="font-medium text-stone-900">{{ $this->stages->get($slot['stage']->value)?->displayName() ?? $slot['stage']->defaultName() }}</span>@if ($product)<span class="text-stone-600">: {{ $product->displayName() }}</span>@endif
                        @if ($product)
                            @if ($product->unit->code !== Unit::BRICK)
                                <span class="text-xs text-stone-500">({{ $product->unit->displayName() }})</span>
                            @endif
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end"><span class="tabular text-stone-600" dir="ltr">{{ $slot['opening']->format($decimals) }}</span></x-ui.td>
                    <x-ui.td align="end">@if (! $slot['in']->isZero())<span class="tabular text-emerald-700" dir="ltr">+{{ $slot['in']->format($decimals) }}</span>@endif</x-ui.td>
                    <x-ui.td align="end">@if (! $slot['out']->isZero())<span class="tabular text-stone-700" dir="ltr">−{{ $slot['out']->format($decimals) }}</span>@endif</x-ui.td>
                    <x-ui.td align="end"><span class="tabular font-semibold text-stone-900" dir="ltr">{{ $slot['closing']->format($decimals) }}</span></x-ui.td>
                </tr>
            @endforeach
        </x-ui.table>

        @php($flagged = $report['entries']->filter(fn ($e) => in_array($e->id, $report['duplicates'], true) || ($e->kind === ProductionEntryKind::Adjustment && ! $e->isReversed())))
        @if ($flagged->isNotEmpty())
            <div class="mb-4 flex items-start gap-2 rounded-[var(--radius-card)] bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="status">
                <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div>
                    <p class="font-medium">{{ __('Worth checking') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($flagged as $entry)
                            <li>
                                <span class="tabular" dir="ltr">{{ $entry->number }}</span> —
                                {{ in_array($entry->id, $report['duplicates'], true) ? __('looks the same as another entry of this day; was it entered twice?') : __('a stock adjustment: :reason', ['reason' => $entry->notes]) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <h2 class="mb-2 text-base font-semibold text-stone-900">{{ __('Entries of the day') }}</h2>
        <x-ui.table>
            <x-slot:head>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-72">{{ __('Details') }}</x-ui.th>
                <x-ui.th>{{ __('By') }}</x-ui.th>
                @can(\App\Enums\Permission::ProductionAdjust->value)
                    <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                @endcan
            </x-slot:head>

            @forelse ($report['entries'] as $entry)
                <tr wire:key="day-entry-{{ $entry->id }}" @class(['align-top', 'bg-red-50/40' => $entry->isReversed()])>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular font-medium text-stone-800" dir="ltr">{{ $entry->number }}</span></x-ui.td>
                    <x-ui.td><x-production.entry-summary :entry="$entry" :stages="$this->stages" :duplicate="in_array($entry->id, $report['duplicates'], true)" /></x-ui.td>
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
                <tr><td colspan="4"><x-ui.empty-state icon="factory" :title="__('Nothing recorded on this day')" /></td></tr>
            @endforelse
        </x-ui.table>

        @if ($this->dispatches->isNotEmpty())
            <h2 class="mb-2 mt-6 text-base font-semibold text-stone-900">{{ __('Delivered to customers') }}</h2>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>{{ __('Number') }}</x-ui.th>
                    <x-ui.th class="min-w-72">{{ __('Details') }}</x-ui.th>
                    <x-ui.th>{{ __('By') }}</x-ui.th>
                </x-slot:head>
                @foreach ($this->dispatches as $dispatch)
                    <tr wire:key="day-dispatch-{{ $dispatch->id }}" @class(['align-top', 'bg-red-50/40' => $dispatch->isReversed()])>
                        <x-ui.td class="whitespace-nowrap">
                            <span class="tabular font-medium text-stone-800" dir="ltr">{{ $dispatch->number }}</span>
                            @if ($dispatch->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2" class="ms-1">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            <p class="font-medium text-stone-900">{{ $dispatch->sale->customer->displayName() }} ·
                                @can(\App\Enums\Permission::SalesView->value)
                                    <a href="{{ route('sales.show', $dispatch->sale) }}" wire:navigate class="tabular text-brand-700 hover:underline" dir="ltr">{{ $dispatch->sale->number }}</a>
                                @else
                                    <span class="tabular" dir="ltr">{{ $dispatch->sale->number }}</span>
                                @endcan
                            </p>
                            <p class="text-sm text-stone-600">
                                @foreach ($dispatch->lines as $dl)
                                    @php($line = $dispatch->sale->lines->firstWhere('id', $dl->sale_line_id))
                                    <span class="whitespace-nowrap">{{ $line?->product->displayName() }} <bdi class="tabular">{{ $dl->quantity->format($line?->product->unit->decimals ?? 0) }}</bdi></span>@if (! $loop->last)<span class="text-stone-300"> · </span>@endif
                                @endforeach
                            </p>
                        </x-ui.td>
                        <x-ui.td class="whitespace-nowrap text-stone-600">{{ $dispatch->creator?->name ?? __('System') }}</x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    @endif

    <livewire:production.entry-forms />
    <x-production.reverse-modal :number="$this->reversingNumber()" />
</div>
