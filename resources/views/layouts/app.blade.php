@php
    /** @var \App\Models\User $authUser */
    $authUser = auth()->user();
    $kiln = app(\App\Support\Kiln\CurrentKiln::class)->get();
    $navigation = \App\Support\Navigation::for($authUser);
    $role = $authUser->kilnRole();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.head', ['title' => $title ?? null])
</head>
<body class="min-h-screen">
<div x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false" class="min-h-screen">
    {{-- Skip link for keyboard users --}}
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[70] focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow-pop">{{ __('Skip to content') }}</a>

    {{-- Mobile backdrop --}}
    <div x-show="sidebarOpen" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-stone-950/50 lg:hidden" x-on:click="sidebarOpen = false" aria-hidden="true"></div>

    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 start-0 z-50 flex w-64 -translate-x-full flex-col bg-sidebar text-stone-300 transition-transform duration-200 ease-out rtl:translate-x-full lg:translate-x-0 lg:rtl:translate-x-0 print:hidden"
        :class="sidebarOpen && 'translate-x-0! rtl:translate-x-0!'"
        aria-label="{{ __('Main navigation') }}"
    >
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/5 px-5">
            @if ($kiln->logo_path)
                <img src="{{ $kiln->logoUrl() }}" alt="" class="size-9 shrink-0 rounded-lg bg-white object-contain p-0.5">
            @else
                <x-brand.logo class="size-9" />
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ config('app.name') }}</p>
                <p class="truncate text-xs text-stone-400">{{ $kiln->displayName() }}</p>
            </div>
            <button type="button" x-on:click="sidebarOpen = false" class="ms-auto rounded-md p-1.5 text-stone-400 hover:bg-sidebar-hover hover:text-white lg:hidden" aria-label="{{ __('Close menu') }}">
                <x-lucide-x class="size-5" aria-hidden="true" />
            </button>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
            @foreach ($navigation as $section)
                <div>
                    <p class="mb-2 px-3 text-[0.6875rem] font-semibold uppercase tracking-wider text-stone-500">{{ $section['label'] }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            @php($active = request()->routeIs($item['active']))
                            <li>
                                <a
                                    href="{{ route($item['route']) }}"
                                    wire:navigate
                                    @if ($active) aria-current="page" @endif
                                    @class([
                                        'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-sidebar-active text-white' => $active,
                                        'text-stone-300 hover:bg-sidebar-hover hover:text-white' => ! $active,
                                    ])
                                >
                                    @if ($active)
                                        <span class="absolute inset-y-1.5 start-0 w-1 rounded-full bg-brand-500" aria-hidden="true"></span>
                                    @endif
                                    <x-dynamic-component :component="'lucide-'.$item['icon']" @class(['size-[1.125rem] shrink-0', 'text-brand-400' => $active, 'text-stone-400 group-hover:text-stone-200' => ! $active]) aria-hidden="true" />
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-white/5 p-4 text-xs text-stone-500">
            {{ __('Signed in as') }} <span class="font-medium text-stone-300">{{ $authUser->username }}</span>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="lg:ps-64 print:ps-0">
        <header class="sticky top-0 z-30 flex h-16 print:hidden items-center gap-3 border-b border-stone-200/80 bg-canvas/85 px-4 backdrop-blur sm:px-6 lg:px-8">
            <button type="button" x-on:click="sidebarOpen = true" class="-ms-1.5 rounded-md p-1.5 text-stone-600 hover:bg-stone-200/60 lg:hidden" aria-label="{{ __('Open menu') }}">
                <x-lucide-menu class="size-5" aria-hidden="true" />
            </button>

            <div class="ms-auto flex items-center gap-3">
                <x-language-switch />

                <div x-data="{ open: false }" class="relative" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" x-on:click="open = !open" class="flex items-center gap-2 rounded-lg p-1 pe-2 hover:bg-stone-200/60" :aria-expanded="open" aria-haspopup="menu">
                        <x-ui.avatar :initials="$authUser->initials()" size="sm" />
                        <span class="hidden text-start sm:block">
                            <span class="block text-sm font-medium leading-tight text-stone-800">{{ $authUser->name }}</span>
                            <span class="block text-xs leading-tight text-stone-500">{{ $role?->label() }}</span>
                        </span>
                        <x-lucide-chevron-down class="size-4 text-stone-400" aria-hidden="true" />
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition.origin.top
                        class="absolute end-0 mt-2 w-56 overflow-hidden rounded-xl bg-white py-1 shadow-pop ring-1 ring-stone-200"
                        role="menu"
                    >
                        <div class="border-b border-stone-100 px-4 py-3 sm:hidden">
                            <p class="text-sm font-medium text-stone-800">{{ $authUser->name }}</p>
                            <p class="text-xs text-stone-500">{{ $role?->label() }}</p>
                        </div>
                        <a href="{{ route('profile.edit') }}" wire:navigate class="flex items-center gap-2.5 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50" role="menuitem">
                            <x-lucide-user-round class="size-4 text-stone-400" aria-hidden="true" /> {{ __('My profile') }}
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50" role="menuitem">
                                <x-lucide-log-out class="size-4 text-stone-400 rtl:rotate-180" aria-hidden="true" /> {{ __('Log out') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8 print:max-w-none print:p-0">
            {{ $slot }}
        </main>
    </div>
</div>

@include('partials.toasts')
</body>
</html>
