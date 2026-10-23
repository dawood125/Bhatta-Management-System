{{--
    What a production entry did, in one cell. Needs the entry with movements.product.unit and
    materialUsage.movements.material.unit loaded, and the kiln's stages keyed by kind.
--}}
@props(['entry', 'stages', 'duplicate' => false])

@php($stageName = fn ($kind) => $kind === null ? null : ($stages->get($kind->value)?->displayName() ?? $kind->defaultName()))

<div class="flex flex-wrap items-center gap-2">
    <x-ui.badge :tone="$entry->kind->tone()" :icon="$entry->kind->icon()">{{ $entry->kind->label() }}</x-ui.badge>
    @if ($entry->isReversed())
        <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
    @endif
    @if ($duplicate)
        <x-ui.badge tone="warning" icon="copy">{{ __('Possible duplicate') }}</x-ui.badge>
    @endif
</div>

<div class="mt-1 space-y-0.5 text-stone-700">
    @switch($entry->kind)
        @case(\App\Enums\ProductionEntryKind::Pathai)
            @if (filled($entry->team))
                <p><bdi>{{ $entry->team }}</bdi></p>
            @endif
            <p class="text-sm">
                {{ __('Made') }} <span class="tabular font-medium" dir="ltr">{{ $entry->made?->format(0) }}</span>
                · {{ __('Rejected') }} <span class="tabular font-medium" dir="ltr">{{ $entry->rejected?->format(0) ?? 0 }}</span>
                · {{ __('Accepted') }} <span class="tabular font-semibold text-stone-900" dir="ltr">{{ $entry->accepted()->format(0) }}</span>
            </p>
            @break
        @case(\App\Enums\ProductionEntryKind::Nakaasi)
            <ul class="grid gap-x-6 text-sm sm:grid-cols-2">
                @foreach ($entry->movements as $movement)
                    <li class="flex justify-between gap-3">
                        <span>{{ $movement->product?->displayName() }}</span>
                        <span class="tabular font-medium" dir="ltr">{{ $movement->quantity->format($movement->product?->unit->decimals ?? 0) }} @if ($movement->product && $movement->product->unit->code !== \App\Models\Unit::BRICK)<span class="text-xs font-normal text-stone-500">{{ $movement->product->unit->symbol }}</span>@endif</span>
                    </li>
                @endforeach
            </ul>
            @break
        @case(\App\Enums\ProductionEntryKind::Adjustment)
            @php($movement = $entry->movements->first())
            @if ($movement)
                <p class="text-sm">
                    {{ $stageName($movement->stage_to ?? $movement->stage_from) }}@if ($movement->product): {{ $movement->product->displayName() }}@endif
                    <span @class(['tabular font-semibold', 'text-emerald-700' => $movement->stage_to !== null, 'text-red-700' => $movement->stage_to === null]) dir="ltr">{{ $movement->stage_to !== null ? '+' : '−' }}{{ $movement->quantity->format($movement->product?->unit->decimals ?? 0) }}</span>
                </p>
            @endif
            @break
        @default
            @if ($entry->quantity->isPositive())
                <p class="text-sm"><span class="tabular font-semibold text-stone-900" dir="ltr">{{ $entry->quantity->format(0) }}</span> {{ __('bricks') }}</p>
            @endif
            @if ($entry->materialUsage)
                <p class="text-xs text-stone-500">
                    {{ __('Fuel used') }}:
                    @foreach ($entry->materialUsage->movements as $fuel)
                        {{ $fuel->material->displayName() }} <span class="tabular" dir="ltr">{{ $fuel->quantity_out->format($fuel->material->unit->decimals) }}</span> {{ $fuel->material->unit->symbol }}@if (! $loop->last), @endif
                    @endforeach
                </p>
            @endif
    @endswitch

    @if (filled($entry->notes))
        <p class="text-xs text-stone-500"><bdi>{{ $entry->notes }}</bdi></p>
    @endif
    @if ($entry->isReversed())
        <p class="text-xs text-red-700">{{ __('Reversed by :name on :date', ['name' => $entry->reverser?->name ?? __('System'), 'date' => $entry->reversed_at?->timezone(config('app.timezone'))->format('d-m-Y')]) }}: <bdi>{{ $entry->reversal_reason }}</bdi></p>
    @endif
</div>
