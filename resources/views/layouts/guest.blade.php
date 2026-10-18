<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.head', ['title' => $title ?? null])
    {{-- Guest pages have no Livewire component, so load Livewire (and its bundled Alpine) explicitly. --}}
    @livewireStyles
</head>
<body class="min-h-screen bg-canvas">
<div class="grid min-h-screen lg:grid-cols-[1.05fr_1fr]">
    {{-- Brand panel (large screens) --}}
    <aside class="relative hidden overflow-hidden bg-sidebar lg:flex lg:flex-col lg:justify-between lg:p-12">
        <svg class="absolute inset-0 size-full opacity-[0.07]" aria-hidden="true">
            <defs>
                <pattern id="bricks" width="64" height="32" patternUnits="userSpaceOnUse">
                    <rect x="1" y="1" width="62" height="14" rx="2" fill="#F2C8B8"/>
                    <rect x="-31" y="17" width="62" height="14" rx="2" fill="#F2C8B8"/>
                    <rect x="33" y="17" width="62" height="14" rx="2" fill="#F2C8B8"/>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#bricks)"/>
        </svg>
        <div class="absolute -bottom-40 -start-40 size-[32rem] rounded-full bg-brand-600/25 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex items-center gap-3">
            <x-brand.logo class="size-10" />
            <span class="text-lg font-semibold text-white">{{ config('app.name') }}</span>
        </div>

        <div class="relative max-w-md">
            <h2 class="text-3xl font-semibold leading-tight text-white">{{ __('Every brick, every rupee, accounted for.') }}</h2>
            <p class="mt-4 text-base text-stone-300">{{ __('Production, labour, stock, sales and accounts for your kiln, in one place.') }}</p>
        </div>

        <p class="relative text-xs text-stone-500">&copy; {{ now()->year }} {{ config('app.name') }}</p>
    </aside>

    {{-- Form panel --}}
    <main class="flex flex-col px-4 py-6 sm:px-8">
        <div class="flex justify-end">
            <x-language-switch />
        </div>
        <div class="flex flex-1 items-center justify-center py-10">
            <div class="w-full max-w-sm">
                {{ $slot }}
            </div>
        </div>
    </main>
</div>

@include('partials.toasts')
@livewireScripts
</body>
</html>
