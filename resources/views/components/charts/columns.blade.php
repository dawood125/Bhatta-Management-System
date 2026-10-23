{{--
    Grouped column chart for money over a few periods (e.g. sales and costs per month).
    categories: list of labels; series: list of ['name' => string, 'values' => list<Money>].
    Up to 2 series (validated categorical slots 1–2 on the white surface). Legend always shown;
    every value is in the hover/focus tooltip and in the table view below.
--}}
@props(['categories', 'series', 'label'])

@php
    $max = 0;
    foreach ($series as $s) {
        foreach ($s['values'] as $value) {
            $max = max($max, $value->paisa);
        }
    }
    $ticks = \App\Support\ChartScale::ticks($max);
    $top = max(end($ticks), 1);
    $colors = ['var(--series-1)', 'var(--series-2)'];
@endphp

<figure {{ $attributes->class('viz-root') }} aria-label="{{ $label }}">
    <style>
        .viz-root { --series-1: #2a78d6; --series-2: #eb6834; --viz-grid: #e7e5e4; --viz-muted: #78716c; }
    </style>

    <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-600">
        @foreach ($series as $i => $s)
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-[2px]" style="background: {{ $colors[$i] }}"></span>{{ $s['name'] }}</span>
        @endforeach
    </div>

    <div class="flex min-w-0 gap-2">
        {{-- Value axis --}}
        <div class="relative h-48 w-14 shrink-0 text-end text-[11px] text-[var(--viz-muted)]" aria-hidden="true">
            @foreach ($ticks as $tick)
                <span class="tabular absolute end-0 translate-y-1/2" style="bottom: {{ $tick / $top * 100 }}%" dir="ltr">{{ \App\Support\ChartScale::label($tick) }}</span>
            @endforeach
        </div>

        <div class="relative min-w-0 flex-1">
            {{-- Gridlines: hairline, solid --}}
            <div class="pointer-events-none absolute inset-x-0 top-0 h-48" aria-hidden="true">
                @foreach ($ticks as $tick)
                    <div class="absolute inset-x-0 border-t" style="bottom: {{ $tick / $top * 100 }}%; border-color: {{ $tick === 0 ? '#d6d3d1' : 'var(--viz-grid)' }}"></div>
                @endforeach
            </div>

            <div class="relative flex h-48 items-end">
                @foreach ($categories as $c => $category)
                    <div class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-[2px] rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        tabindex="0" x-data="{ tip: false }" x-on:mouseenter="tip = true" x-on:mouseleave="tip = false" x-on:focus="tip = true" x-on:blur="tip = false"
                        aria-label="{{ $category }}: @foreach ($series as $s){{ $s['name'] }} {{ $s['values'][$c]->formatWithCurrency() }}@if (! $loop->last), @endif @endforeach">
                        <div class="absolute inset-0 rounded-sm bg-stone-100/0 transition group-hover:bg-stone-100/60" aria-hidden="true"></div>
                        @foreach ($series as $i => $s)
                            @php($value = $s['values'][$c]->paisa)
                            <span class="relative w-full max-w-6 rounded-t-[4px] transition group-hover:brightness-110" style="height: {{ $value > 0 ? max($value / $top * 100, 0.8) : 0 }}%; background: {{ $colors[$i] }}" aria-hidden="true"></span>
                        @endforeach

                        {{-- Tooltip: values lead, names follow --}}
                        <div x-show="tip" x-cloak class="pointer-events-none absolute bottom-full start-1/2 z-10 mb-2 w-max min-w-36 -translate-x-1/2 rounded-lg bg-white px-3 py-2 text-xs shadow-pop ring-1 ring-stone-200 rtl:translate-x-1/2">
                            <p class="mb-1 font-medium text-stone-500">{{ $category }}</p>
                            @foreach ($series as $i => $s)
                                <p class="flex items-center gap-2">
                                    <span class="h-0.5 w-3 rounded-full" style="background: {{ $colors[$i] }}" aria-hidden="true"></span>
                                    <span class="tabular font-semibold text-stone-900" dir="ltr">{{ $s['values'][$c]->format() }}</span>
                                    <span class="text-stone-500">{{ $s['name'] }}</span>
                                </p>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-2 flex text-[11px] text-[var(--viz-muted)]" aria-hidden="true">
                @foreach ($categories as $category)
                    <span class="min-w-0 flex-1 truncate text-center">{{ $category }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <details class="mt-3 text-xs text-stone-600">
        <summary class="cursor-pointer select-none font-medium text-stone-500 hover:text-stone-800">{{ __('Show as a table') }}</summary>
        <table class="mt-2 w-full">
            <thead>
                <tr class="text-stone-500">
                    <th class="py-1 text-start font-medium">{{ __('Period') }}</th>
                    @foreach ($series as $s)
                        <th class="py-1 text-end font-medium">{{ $s['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $c => $category)
                    <tr class="border-t border-stone-100">
                        <td class="py-1">{{ $category }}</td>
                        @foreach ($series as $s)
                            <td class="tabular py-1 text-end" dir="ltr">{{ $s['values'][$c]->format() }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
