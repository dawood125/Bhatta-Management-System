@props([
    'variant' => 'primary', // primary | secondary | ghost | danger
    'size' => 'md',         // sm | md | lg
    'icon' => null,         // lucide icon name, e.g. "plus"
    'type' => 'button',
    'href' => null,
    'loading' => null,      // Livewire method name: disables the button and shows a spinner while it runs
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center justify-center gap-2 rounded-[var(--radius-control)] font-medium whitespace-nowrap',
        'transition-colors duration-150 select-none cursor-pointer',
        'focus-visible:outline-2 focus-visible:outline-offset-2',
        'disabled:cursor-not-allowed disabled:opacity-60',
        match ($size) {
            'sm' => 'h-8 px-3 text-sm',
            'lg' => 'h-11 px-5 text-base',
            default => 'h-10 px-4 text-sm',
        },
        match ($variant) {
            'secondary' => 'bg-white text-stone-700 ring-1 ring-inset ring-stone-300 shadow-xs hover:bg-stone-50 hover:text-stone-900 focus-visible:outline-brand-500',
            'ghost' => 'text-stone-600 hover:bg-stone-100 hover:text-stone-900 focus-visible:outline-brand-500',
            'danger' => 'bg-red-600 text-white shadow-xs hover:bg-red-700 focus-visible:outline-red-600',
            default => 'bg-brand-600 text-white shadow-xs hover:bg-brand-700 focus-visible:outline-brand-600',
        },
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-dynamic-component :component="'lucide-'.$icon" class="size-4 shrink-0" aria-hidden="true" />
        @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
        {{ $attributes->class($classes) }}
    >
        @if ($loading)
            <x-lucide-loader-circle wire:loading wire:target="{{ $loading }}" class="size-4 shrink-0 animate-spin" aria-hidden="true" />
            @if ($icon)
                <x-dynamic-component :component="'lucide-'.$icon" wire:loading.remove wire:target="{{ $loading }}" class="size-4 shrink-0" aria-hidden="true" />
            @endif
        @elseif ($icon)
            <x-dynamic-component :component="'lucide-'.$icon" class="size-4 shrink-0" aria-hidden="true" />
        @endif
        {{ $slot }}
    </button>
@endif
