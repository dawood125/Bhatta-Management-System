{{--
    Dialog bound to a boolean Livewire property:  <x-ui.modal wire:model="showForm" :title="...">
    Bottom sheet on phones, centred dialog on larger screens. Esc / backdrop click closes it.
--}}
@props([
    'title' => null,
    'description' => null,
    'maxWidth' => 'lg', // sm | md | lg | xl
])

@php
    $property = $attributes->wire('model')->value();
    $width = match ($maxWidth) {
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'xl' => 'sm:max-w-2xl',
        default => 'sm:max-w-lg',
    };
@endphp

<div
    x-data="{ open: $wire.entangle('{{ $property }}') }"
    x-show="open"
    x-cloak
    x-on:keydown.escape.window="open = false"
    class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
    role="dialog"
    aria-modal="true"
    @if ($title) aria-labelledby="{{ $property }}-title" @endif
>
    <div x-show="open" x-transition.opacity.duration.150ms class="fixed inset-0 bg-stone-950/50" x-on:click="open = false" aria-hidden="true"></div>

    <div
        x-show="open"
        x-trap.noscroll="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-3 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        {{ $attributes->except('wire:model')->class("relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-pop sm:rounded-[var(--radius-card)] {$width}") }}
    >
        @if ($title)
            <div class="flex items-start justify-between gap-4 border-b border-stone-200 px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <h2 id="{{ $property }}-title" class="text-base font-semibold text-stone-900">{{ $title }}</h2>
                    @if ($description)
                        <p class="mt-0.5 text-sm text-stone-500">{{ $description }}</p>
                    @endif
                </div>
                <button type="button" x-on:click="open = false" class="-m-1.5 rounded-md p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-600" aria-label="{{ __('Close') }}">
                    <x-lucide-x class="size-5" aria-hidden="true" />
                </button>
            </div>
        @endif

        <div class="overflow-y-auto px-5 py-5 sm:px-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-stone-200 bg-stone-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
