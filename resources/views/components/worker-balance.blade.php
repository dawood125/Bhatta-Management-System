{{--
    A worker's balance in plain words: "Wages due" (the kiln owes the worker) or "Advance" (the
    worker owes the kiln). The direction is written out, never shown by colour alone.
--}}
@props(['amount', 'size' => 'sm'])

<span {{ $attributes->class(['inline-flex flex-wrap items-center gap-x-2 gap-y-0.5', 'justify-end' => $size === 'sm']) }}>
    @if ($amount->isZero())
        <span class="text-stone-400">{{ __('Settled') }}</span>
    @else
        <x-ui.badge :tone="$amount->isPositive() ? 'warning' : 'info'">
            {{ $amount->isPositive() ? __('Wages due') : __('Advance') }}
        </x-ui.badge>
        <x-ui.money :amount="$amount->abs()" class="font-semibold text-stone-900 {{ $size === 'lg' ? 'text-2xl' : '' }}" />
    @endif
</span>
