<div>
    <div class="mb-8">
        <p class="text-sm font-medium text-brand-700">{{ now()->translatedFormat('l, j F Y') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900 sm:text-3xl">{{ $greeting }}, {{ $user->name }}</h1>
        <p class="mt-1 text-sm text-stone-500">{{ $kiln->displayName() }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.card>
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-lucide-shield-check class="size-5" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Your role') }}</p>
                    <p class="truncate text-base font-semibold text-stone-900">{{ $user->kilnRole()?->label() }}</p>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><x-lucide-users class="size-5" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Active staff accounts') }}</p>
                    <p class="tabular text-base font-semibold text-stone-900">{{ number_format($activeStaff) }}</p>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-lg bg-sky-50 text-sky-600"><x-lucide-clock class="size-5" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Logged in') }}</p>
                    <p class="truncate text-base font-semibold text-stone-900">{{ $user->last_login_at?->diffForHumans() ?? '—' }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card class="mt-6">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
            <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-stone-100 text-stone-500">
                <x-lucide-factory class="size-7" aria-hidden="true" />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold text-stone-900">{{ __('Your kiln workspace is ready') }}</h2>
                <p class="mt-1 text-sm text-stone-500">{{ __('Production, stock, labour, sales and accounts will appear here as each module is completed and approved.') }}</p>
            </div>
            @if ($canManageUsers)
                <x-ui.button :href="route('users.index')" variant="secondary" icon="users" wire:navigate>{{ __('Manage staff accounts') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>
</div>
