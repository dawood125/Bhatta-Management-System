{{-- Wrapper for every "Kiln settings" page: common header + tab bar. --}}
@props(['title', 'description' => null])

@php
    $tabs = \App\Support\Navigation::settingsTabs(auth()->user());
@endphp

<div>
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ __('Kiln settings') }}</h1>
        <p class="mt-1 text-sm text-stone-500">{{ __('Names, lists and options used across the system. Changes apply to this kiln only.') }}</p>
    </div>

    <nav
        class="-mx-4 mb-6 overflow-x-auto border-b border-stone-200 px-4 [scrollbar-width:none] sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden"
        aria-label="{{ __('Kiln settings') }}"
        x-data
        x-init="$el.querySelector('[aria-current]')?.scrollIntoView({ block: 'nearest', inline: 'center' })"
    >
        <ul class="flex min-w-max gap-1">
            @foreach ($tabs as $tab)
                @php($active = request()->routeIs($tab['route']))
                <li>
                    <a
                        href="{{ route($tab['route']) }}"
                        wire:navigate
                        @if ($active) aria-current="page" @endif
                        @class([
                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors',
                            'border-brand-600 text-brand-700' => $active,
                            'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-800' => ! $active,
                        ])
                    >
                        <x-dynamic-component :component="'lucide-'.$tab['icon']" class="size-4" aria-hidden="true" />
                        {{ $tab['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-stone-900">{{ $title }}</h2>
            @if ($description)
                <p class="mt-0.5 max-w-3xl text-sm text-stone-500">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex shrink-0 flex-wrap gap-2">{{ $actions }}</div>
        @endisset
    </div>

    {{ $slot }}
</div>
