{{-- A dashboard figure: label, value and an optional hint; the whole tile links to its page. --}}
@props(['label', 'hint' => null, 'href' => null, 'tone' => null])

@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class([
    'block rounded-[var(--radius-card)] bg-surface px-4 py-3.5 shadow-card ring-1 ring-stone-200/80',
    'transition hover:ring-brand-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500' => $href,
]) }}>
    <p class="text-xs font-medium text-stone-500">{{ $label }}</p>
    <p @class(['mt-1 text-xl font-semibold', 'text-stone-900' => $tone === null, 'text-emerald-700' => $tone === 'good', 'text-red-700' => $tone === 'bad'])>{{ $slot }}</p>
    @if ($hint)
        <p class="mt-0.5 text-xs text-stone-500">{{ $hint }}</p>
    @endif
</{{ $tag }}>
