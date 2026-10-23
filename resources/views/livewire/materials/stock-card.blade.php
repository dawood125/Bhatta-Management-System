@php use App\Enums\MaterialMovementType; @endphp
@php($decimals = $material->unit->decimals)
@php($unit = $material->unit->symbol ?? $material->unit->displayName())
<div>
    <div class="mb-2 print:hidden">
        <a href="{{ route('materials.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Materials') }}
        </a>
    </div>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="hidden text-sm font-semibold text-stone-700 print:block">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ __('Stock card') }}: {{ $material->displayName() }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-stone-500">
                <x-ui.badge :tone="$material->kind->tone()">{{ $material->kind->label() }}</x-ui.badge>
                <span>{{ __('Unit') }}: {{ $material->unit->displayName() }}</span>
            </div>
        </div>
        <div class="flex shrink-0 flex-col items-start gap-1 sm:items-end">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('In stock now') }}</p>
            <p class="text-2xl font-semibold text-stone-900"><span class="tabular" dir="ltr">{{ $this->current['quantity']->format($decimals) }}</span> <span class="text-base font-normal text-stone-500">{{ $unit }}</span></p>
            <p class="text-sm text-stone-600"><x-ui.money :amount="$this->current['value']" />@if ($average) · <x-ui.money :amount="$average" /> / {{ $unit }}@endif</p>
            <x-ui.button size="sm" variant="secondary" icon="printer" x-on:click="window.print()" class="mt-1 print:hidden">{{ __('Print') }}</x-ui.button>
        </div>
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="card-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        @php($card = $this->card)
        <p class="mb-2 hidden text-sm text-stone-600 print:block">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>

        <x-ui.table wire:loading.class="opacity-60" wire:target="from,to,setRange">
            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-56">{{ __('Details') }}</x-ui.th>
                <x-ui.th align="end">{{ __('In') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Out') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Value') }}</x-ui.th>
                @if ($canReverse)
                    <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                @endif
            </x-slot:head>

            <tr class="bg-stone-50/60">
                <x-ui.td colspan="5" class="font-medium text-stone-600">{{ __('Stock brought forward') }}</x-ui.td>
                <x-ui.td align="end" class="font-semibold"><span class="tabular" dir="ltr">{{ $card['opening']['quantity']->format($decimals) }}</span></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$card['opening']['value']" :currency="false" /></x-ui.td>
                @if ($canReverse)
                    <x-ui.td class="print:hidden" />
                @endif
            </tr>

            @forelse ($card['rows'] as $row)
                @php($movement = $row['movement'])
                @php($original = $movement->reversalOf ?? $movement)
                <tr wire:key="move-{{ $movement->id }}" @class(['align-top', 'bg-red-50/40' => $movement->type === MaterialMovementType::Reversal || $movement->entry->isReversed()])>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $movement->movement_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        @if ($original->purchaseLine)
                            <a href="{{ route('materials.purchases.show', $original->purchaseLine->material_purchase_id) }}" wire:navigate class="tabular text-stone-700 hover:underline" dir="ltr">{{ $movement->entry->number }}</a>
                        @else
                            <span class="tabular text-stone-600" dir="ltr">{{ $movement->entry->number }}</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="$movement->type->tone()">{{ $movement->type->label() }}</x-ui.badge>
                            @if ($movement->entry->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        @if ($movement->type === MaterialMovementType::Purchase && $movement->purchaseLine?->purchase->supplier)
                            <p class="mt-1 text-stone-700"><bdi>{{ $movement->purchaseLine->purchase->supplier->displayName() }}</bdi></p>
                        @endif
                        @if ($movement->type === MaterialMovementType::Reversal)
                            <p class="mt-1 text-xs text-stone-500">{{ __('Reverses :number', ['number' => $original->entry->number]) }}</p>
                        @endif
                        @if (filled($movement->memo))
                            <p class="mt-0.5 text-xs text-stone-500"><bdi>{{ $movement->memo }}</bdi></p>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end" class="whitespace-nowrap">@if ($movement->quantity_in->isPositive())<span class="tabular text-emerald-700" dir="ltr">{{ $movement->quantity_in->format($decimals) }}</span>@endif</x-ui.td>
                    <x-ui.td align="end" class="whitespace-nowrap">@if ($movement->quantity_out->isPositive())<span class="tabular" dir="ltr">{{ $movement->quantity_out->format($decimals) }}</span>@endif</x-ui.td>
                    <x-ui.td align="end" class="whitespace-nowrap font-medium"><span class="tabular" dir="ltr">{{ $row['quantity']->format($decimals) }}</span></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$row['value']" :currency="false" /></x-ui.td>
                    @if ($canReverse)
                        <x-ui.td align="end" class="print:hidden">
                            @if ($movement->type !== MaterialMovementType::Reversal && ! $movement->entry->isReversed() && $movement->type !== MaterialMovementType::Purchase)
                                <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $movement->journal_entry_id }})" :aria-label="__('Reverse :number', ['number' => $movement->entry->number])" :title="__('Reverse')" />
                            @endif
                        </x-ui.td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canReverse ? 8 : 7 }}"><x-ui.empty-state icon="package" :title="__('Nothing in this period')" /></td></tr>
            @endforelse

            <tr class="bg-stone-50/80 font-semibold">
                <x-ui.td colspan="5">{{ __('Stock at the end') }}</x-ui.td>
                <x-ui.td align="end"><span class="tabular" dir="ltr">{{ $card['closing']['quantity']->format($decimals) }}</span> <span class="text-xs font-normal text-stone-500">{{ $unit }}</span></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$card['closing']['value']" :currency="false" /></x-ui.td>
                @if ($canReverse)
                    <x-ui.td class="print:hidden" />
                @endif
            </tr>
        </x-ui.table>
        <p class="mt-2 text-xs text-stone-500 print:hidden">{{ __('Purchases are reversed from the purchase page, where all its materials and charges are shown.') }}</p>
    @endif

    <x-materials.reverse-modal :number="$this->reversingNumber()" />
</div>
