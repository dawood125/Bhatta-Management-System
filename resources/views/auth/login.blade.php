<x-layouts::guest :title="__('Log in')">
    <div class="mb-8 flex items-center gap-3 lg:hidden">
        <x-brand.logo class="size-10" />
        <span class="text-lg font-semibold text-stone-900">{{ config('app.name') }}</span>
    </div>

    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ __('Welcome back') }}</h1>
    <p class="mt-1.5 text-sm text-stone-500">{{ __('Log in with the username given to you by the kiln owner.') }}</p>

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5" x-data="{ submitting: false, showPassword: false }" x-on:submit="submitting = true">
        @csrf

        <x-ui.field :label="__('Username or email')" for="login">
            <x-ui.input
                id="login"
                name="login"
                icon="user-round"
                :value="old('login')"
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                dir="ltr"
                required
                autofocus
            />
        </x-ui.field>

        <x-ui.field :label="__('Password')" for="password">
            <div class="relative">
                <x-lucide-lock class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
                <input
                    id="password"
                    name="password"
                    :type="showPassword ? 'text' : 'password'"
                    type="password"
                    autocomplete="current-password"
                    dir="ltr"
                    required
                    class="block h-10 w-full rounded-[var(--radius-control)] border border-stone-300 bg-white ps-9 pe-10 text-sm text-stone-900 shadow-xs transition focus:border-brand-500 focus:outline-none focus:ring-3 focus:ring-brand-500/20"
                />
                <button type="button" x-on:click="showPassword = !showPassword" class="absolute end-2 top-1/2 -translate-y-1/2 rounded p-1 text-stone-400 hover:text-stone-600" :aria-label="showPassword ? @js(__('Hide password')) : @js(__('Show password'))">
                    <x-lucide-eye x-show="!showPassword" class="size-4" aria-hidden="true" />
                    <x-lucide-eye-off x-show="showPassword" x-cloak class="size-4" aria-hidden="true" />
                </button>
            </div>
        </x-ui.field>

        <x-ui.checkbox name="remember" id="remember" value="1" :label="__('Keep me logged in on this device')" />

        <x-ui.button type="submit" size="lg" class="w-full" x-bind:disabled="submitting">
            <x-lucide-loader-circle x-show="submitting" x-cloak class="size-4 animate-spin" aria-hidden="true" />
            {{ __('Log in') }}
        </x-ui.button>
    </form>

    <p class="mt-8 text-center text-xs text-stone-500">{{ __('Forgot your password? Ask the kiln owner or manager to reset it.') }}</p>
</x-layouts::guest>
