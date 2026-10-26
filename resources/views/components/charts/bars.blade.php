{{--
    Horizontal bars comparing amounts (one hue: magnitude, not identity), each labelled with
    its name and value, so no legend or tooltip is needed to read it.
    rows: list of ['label' => string, 'amount' => Money].
--}}
@props(['rows', 'label'])

@php($max = max(1, ...array_map(fn ($row) => $row['amount']->paisa, $rows ?: [['amount' => \App\Support\Money::zero()]])))

<figure {{ $attributes->class('space-y-3') }} aria-label="{{ $label }}">
    @forelse ($rows as $row)
        <div>
            <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <span class="text-stone-700">{{ $row['label'] }}</span>
                <span class="tabular font-semibold text-stone-900" dir="ltr">{{ $row['amount']->format() }}</span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-stone-100" aria-hidden="true">
                <div class="h-2.5 rounded-full" style="width: {{ $row['amount']->isPositive() ? max($row['amount']->paisa / $max * 100, 1) : 0 }}%; background: #2a78d6"></div>
            </div>
        </div>
    @empty
        <p class="text-sm text-stone-400">{{ __('Nothing yet') }}</p>
    @endforelse
</figure>
