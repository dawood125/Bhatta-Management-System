@props([
    'icon' => null,   // leading lucide icon
    'suffix' => null, // short trailing text, e.g. "Rs" or "bricks"
])

@php
    $name = $attributes->wire('model')->value() ?: $attributes->get('name');
    $invalid = $name && $errors->has($name);
    $id = $attributes->get('id', $name);
@endphp

<div class="relative">
    @if ($icon)
        <x-dynamic-component :component="'lucide-'.$icon" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
    @endif

    <input
        id="{{ $id }}"
        @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->class([
            'block h-10 w-full rounded-[var(--radius-control)] border bg-white px-3 text-sm text-stone-900 shadow-xs',
            'placeholder:text-stone-400 transition focus:outline-none focus:ring-3',
            'disabled:cursor-not-allowed disabled:bg-stone-100 disabled:text-stone-500 read-only:bg-stone-50',
            'ps-9' => $icon,
            'pe-14' => $suffix,
            'border-stone-300 focus:border-brand-500 focus:ring-brand-500/20' => ! $invalid,
            'border-red-400 focus:border-red-500 focus:ring-red-500/20' => $invalid,
        ]) }}
    />

    @if ($suffix)
        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-sm text-stone-500">{{ $suffix }}</span>
    @endif
</div>
