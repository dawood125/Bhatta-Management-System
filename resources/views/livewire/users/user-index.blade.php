<div>
    <x-ui.page-header :title="__('Users')" :description="__('Staff accounts that can log in to this kiln, and what each person is allowed to do.')">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button icon="user-plus" wire:click="create">{{ __('Add user') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:toolbar>
            <div class="w-full sm:max-w-xs">
                <label for="user-search" class="sr-only">{{ __('Search') }}</label>
                <x-ui.input id="user-search" icon="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search name, username or phone')" type="search" />
            </div>
            <div class="w-full sm:w-44">
                <label for="user-status" class="sr-only">{{ __('Status') }}</label>
                <x-ui.select id="user-status" wire:model.live="status" :options="['' => __('All statuses'), 'active' => __('Active'), 'inactive' => __('Deactivated')]" />
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th sortable="name" :sort-field="$this->activeSortField()" :sort-direction="$this->activeSortDirection()">{{ __('Name') }}</x-ui.th>
            <x-ui.th sortable="username" :sort-field="$this->activeSortField()" :sort-direction="$this->activeSortDirection()">{{ __('Username') }}</x-ui.th>
            <x-ui.th>{{ __('Role') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th sortable="last_login_at" :sort-field="$this->activeSortField()" :sort-direction="$this->activeSortDirection()">{{ __('Last login') }}</x-ui.th>
            @if ($canManage)
                <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
            @endif
        </x-slot:head>

        @forelse ($this->users as $user)
            @php($role = $user->kilnRole())
            @php($locked = $role === \App\Enums\Role::Owner && ! $actor->isOwner())
            <tr wire:key="user-{{ $user->id }}" class="transition-colors hover:bg-stone-50/70" wire:loading.class="opacity-60" wire:target="search,status,sortBy,gotoPage,nextPage,previousPage">
                <x-ui.td>
                    <div class="flex items-center gap-3">
                        <x-ui.avatar :initials="$user->initials()" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate font-medium text-stone-900">
                                {{ $user->name }}
                                @if ($user->is($actor))
                                    <span class="text-xs font-normal text-stone-500">({{ __('you') }})</span>
                                @endif
                            </p>
                            @if ($user->phone)
                                <p class="tabular truncate text-xs text-stone-500" dir="ltr">{{ $user->phone }}</p>
                            @endif
                        </div>
                    </div>
                </x-ui.td>
                <x-ui.td><span class="tabular text-stone-600" dir="ltr">{{ $user->username }}</span></x-ui.td>
                <x-ui.td>
                    <x-ui.badge :tone="$role === \App\Enums\Role::Owner ? 'brand' : 'neutral'">{{ $role?->label() ?? '—' }}</x-ui.badge>
                </x-ui.td>
                <x-ui.td>
                    @if ($user->is_active)
                        <x-ui.badge tone="success" icon="circle-check">{{ __('Active') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="danger" icon="user-x">{{ __('Deactivated') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap text-stone-500">
                    @if ($user->last_login_at)
                        <span title="{{ $user->last_login_at->translatedFormat('j M Y, g:i A') }}">{{ $user->last_login_at->diffForHumans() }}</span>
                    @else
                        <span class="text-stone-400">{{ __('Never') }}</span>
                    @endif
                </x-ui.td>
                @if ($canManage)
                    <x-ui.td align="end">
                        @unless ($locked)
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $user->id }})" :aria-label="__('Edit :name', ['name' => $user->name])">
                                    <span class="hidden xl:inline">{{ __('Edit') }}</span>
                                </x-ui.button>
                                @unless ($user->is($actor))
                                    <x-ui.button size="sm" variant="ghost" icon="key-round" wire:click="openPasswordReset({{ $user->id }})" :aria-label="__('Reset password for :name', ['name' => $user->name])" :title="__('Reset password')" />
                                    <x-ui.button size="sm" variant="ghost" :icon="$user->is_active ? 'user-x' : 'user-check'" wire:click="confirmStatusChange({{ $user->id }})" :aria-label="$user->is_active ? __('Deactivate :name', ['name' => $user->name]) : __('Activate :name', ['name' => $user->name])" :title="$user->is_active ? __('Deactivate') : __('Activate')" />
                                @endunless
                            </div>
                        @endunless
                    </x-ui.td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $canManage ? 6 : 5 }}">
                    <x-ui.empty-state icon="users" :title="__('No users found')" :description="$search !== '' || $status !== '' ? __('Try a different search or filter.') : __('Add the people who will use the system at this kiln.')" />
                </td>
            </tr>
        @endforelse

        @if ($this->users->hasPages())
            <x-slot:footer>
                {{ $this->users->links() }}
            </x-slot:footer>
        @endif
    </x-ui.table>

    {{-- Create / edit user --}}
    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit user') : __('Add user')" :description="$editingId ? null : __('The person will log in with this username and password.')">
        <form id="user-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Full name')" for="form-name" error="form.name" required class="sm:col-span-2">
                <x-ui.input id="form-name" wire:model="form.name" autocomplete="off" />
            </x-ui.field>

            <x-ui.field :label="__('Username')" for="form-username" error="form.username" required :hint="__('Small letters, numbers, dot, dash or underscore.')">
                <x-ui.input id="form-username" wire:model="form.username" autocapitalize="none" spellcheck="false" dir="ltr" autocomplete="off" />
            </x-ui.field>

            <x-ui.field :label="__('Role')" for="form-role" error="form.role" required>
                <x-ui.select id="form-role" wire:model="form.role" :options="$this->roleOptions" :disabled="$editingId === $actor->id" />
            </x-ui.field>

            <x-ui.field :label="__('Phone')" for="form-phone" error="form.phone">
                <x-ui.input id="form-phone" wire:model="form.phone" inputmode="tel" dir="ltr" placeholder="03XX XXXXXXX" />
            </x-ui.field>

            <x-ui.field :label="__('Email')" for="form-email" error="form.email" :hint="__('Optional')">
                <x-ui.input id="form-email" wire:model="form.email" type="email" dir="ltr" />
            </x-ui.field>

            <x-ui.field :label="__('Language')" for="form-locale" error="form.locale" required class="sm:col-span-2">
                <x-ui.select id="form-locale" wire:model="form.locale" :options="['ur' => 'اردو (Urdu)', 'en' => 'English']" />
            </x-ui.field>

            @if ($editingId === null)
                <x-ui.field :label="__('Password')" for="form-password" error="form.password" required :hint="__('At least 8 characters, with letters and numbers.')">
                    <x-ui.input id="form-password" wire:model="form.password" type="password" autocomplete="new-password" dir="ltr" />
                </x-ui.field>

                <x-ui.field :label="__('Confirm password')" for="form-password-confirmation" error="form.password_confirmation" required>
                    <x-ui.input id="form-password-confirmation" wire:model="form.password_confirmation" type="password" autocomplete="new-password" dir="ltr" />
                </x-ui.field>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="user-form" icon="check" loading="save">{{ $editingId ? __('Save changes') : __('Create user') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Reset password --}}
    <x-ui.modal wire:model="showPasswordForm" :title="__('Reset password')" :description="$this->passwordUser ? __('Set a new password for :name. They will be logged out of all devices.', ['name' => $this->passwordUser->name]) : null" max-width="md">
        <form id="password-form" wire:submit="resetPassword" class="space-y-4">
            <x-ui.field :label="__('New password')" for="reset-password" error="passwordForm.password" required :hint="__('At least 8 characters, with letters and numbers.')">
                <x-ui.input id="reset-password" wire:model="passwordForm.password" type="password" autocomplete="new-password" dir="ltr" />
            </x-ui.field>
            <x-ui.field :label="__('Confirm password')" for="reset-password-confirmation" error="passwordForm.password_confirmation" required>
                <x-ui.input id="reset-password-confirmation" wire:model="passwordForm.password_confirmation" type="password" autocomplete="new-password" dir="ltr" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="password-form" icon="key-round" loading="resetPassword">{{ __('Set new password') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Activate / deactivate --}}
    <x-ui.modal wire:model="showStatusConfirm" :title="$this->statusUser?->is_active ? __('Deactivate user?') : __('Activate user?')" max-width="md">
        @if ($this->statusUser)
            <p class="text-sm text-stone-600">
                @if ($this->statusUser->is_active)
                    {{ __(':name will be logged out immediately and will not be able to log in. Their past entries stay in the records.', ['name' => $this->statusUser->name]) }}
                @else
                    {{ __(':name will be able to log in again with their existing password.', ['name' => $this->statusUser->name]) }}
                @endif
            </p>
            @error('user')
                <p class="mt-3 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
            @enderror
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            @if ($this->statusUser?->is_active)
                <x-ui.button variant="danger" icon="user-x" wire:click="changeStatus" loading="changeStatus">{{ __('Deactivate') }}</x-ui.button>
            @else
                <x-ui.button icon="user-check" wire:click="changeStatus" loading="changeStatus">{{ __('Activate') }}</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>
