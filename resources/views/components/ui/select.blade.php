@props([
    'options' => [],      // [value => label]
    'placeholder' => null,
])

@php
    $name = $attributes->wire('model')->value() ?: $attributes->get('name');
    $invalid = $name && $errors->has($name);
    $id = $attributes->get('id', $name);
@endphp

<div class="relative">
    <select
        id="{{ $id }}"
        @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->class([
            'block h-10 w-full appearance-none rounded-[var(--radius-control)] border bg-white ps-3 pe-9 text-sm text-stone-900 shadow-xs',
            'transition focus:outline-none focus:ring-3 disabled:cursor-not-allowed disabled:bg-stone-100',
            'border-stone-300 focus:border-brand-500 focus:ring-brand-500/20' => ! $invalid,
            'border-red-400 focus:border-red-500 focus:ring-red-500/20' => $invalid,
        ]) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
        {{ $slot }}
    </select>
    <x-lucide-chevron-down class="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
</div>
