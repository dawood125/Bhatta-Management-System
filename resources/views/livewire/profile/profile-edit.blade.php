<div>
    <x-ui.page-header :title="__('My profile')" :description="__('Your personal details, language and password.')" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-1">
            <x-ui.card>
                <div class="flex items-center gap-4">
                    <x-ui.avatar :initials="$user->initials()" />
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-stone-900">{{ $user->name }}</p>
                        <p class="tabular truncate text-sm text-stone-500" dir="ltr">{{ $user->username }}</p>
                    </div>
                </div>
                <dl class="mt-5 space-y-3 border-t border-stone-100 pt-5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-stone-500">{{ __('Role') }}</dt>
                        <dd class="font-medium text-stone-800">{{ $user->kilnRole()?->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-stone-500">{{ __('Last login') }}</dt>
                        <dd class="text-stone-800">{{ $user->last_login_at?->diffForHumans() ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-5 text-xs text-stone-500">{{ __('Your username and role can only be changed by the kiln owner or manager.') }}</p>
            </x-ui.card>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                <h2 class="text-base font-semibold text-stone-900">{{ __('Personal details') }}</h2>
                <form wire:submit="saveProfile" class="mt-5 grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Full name')" for="profile-name" error="profile.name" required class="sm:col-span-2">
                        <x-ui.input id="profile-name" wire:model="profile.name" autocomplete="name" />
                    </x-ui.field>
                    <x-ui.field :label="__('Phone')" for="profile-phone" error="profile.phone">
                        <x-ui.input id="profile-phone" wire:model="profile.phone" inputmode="tel" dir="ltr" />
                    </x-ui.field>
                    <x-ui.field :label="__('Email')" for="profile-email" error="profile.email" :hint="__('Optional')">
                        <x-ui.input id="profile-email" wire:model="profile.email" type="email" dir="ltr" />
                    </x-ui.field>
                    <x-ui.field :label="__('Language')" for="profile-locale" error="profile.locale" required class="sm:col-span-2">
                        <x-ui.select id="profile-locale" wire:model="profile.locale" :options="['ur' => 'اردو (Urdu)', 'en' => 'English']" />
                    </x-ui.field>
                    <div class="flex justify-end sm:col-span-2">
                        <x-ui.button type="submit" icon="check" loading="saveProfile">{{ __('Save details') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card>
                <h2 class="text-base font-semibold text-stone-900">{{ __('Change password') }}</h2>
                <p class="mt-1 text-sm text-stone-500">{{ __('After changing it, you stay logged in here and other devices are logged out.') }}</p>
                <form wire:submit="changePassword" class="mt-5 grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Current password')" for="current-password" error="passwordForm.current_password" required class="sm:col-span-2">
                        <x-ui.input id="current-password" wire:model="passwordForm.current_password" type="password" autocomplete="current-password" dir="ltr" />
                    </x-ui.field>
                    <x-ui.field :label="__('New password')" for="new-password" error="passwordForm.password" required :hint="__('At least 8 characters, with letters and numbers.')">
                        <x-ui.input id="new-password" wire:model="passwordForm.password" type="password" autocomplete="new-password" dir="ltr" />
                    </x-ui.field>
                    <x-ui.field :label="__('Confirm password')" for="new-password-confirmation" error="passwordForm.password_confirmation" required>
                        <x-ui.input id="new-password-confirmation" wire:model="passwordForm.password_confirmation" type="password" autocomplete="new-password" dir="ltr" />
                    </x-ui.field>
                    <div class="flex justify-end sm:col-span-2">
                        <x-ui.button type="submit" icon="key-round" loading="changePassword">{{ __('Change password') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
</div>
