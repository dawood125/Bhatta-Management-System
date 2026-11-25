<div>
    <x-ui.page-header :title="__('Data')" :description="__('Bring in your lists and opening stock from Excel, download all your data, or start again.')" />

    {{-- Import / export are added below reset --}}
    @includeIf('livewire.data.partials.import-export')

    {{-- Reset --}}
    <x-ui.card class="mt-6">
        <h2 class="text-base font-semibold text-stone-900">{{ __('Reset data') }}</h2>
        <p class="mt-1 text-sm text-stone-600">{{ __('For example after testing or training, before the real work starts. A backup is made first, so it can be undone from Backups.') }}</p>

        @if ($resetDone)
            <div class="mt-4 rounded-[var(--radius-control)] bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200" role="status">
                <p class="font-medium">{{ $resetDone['mode'] === 'factory' ? __('Factory reset done.') : __('All entries cleared.') }}</p>
                @if ($resetDone['backup'] && $resetDone['disk'])
                    <p class="mt-1">{{ __('The backup made just before:') }}
                        <a href="{{ route('backups.download', ['disk' => $resetDone['disk'], 'file' => $resetDone['backup']]) }}" class="font-medium underline" dir="ltr">{{ $resetDone['backup'] }}</a>
                    </p>
                @endif
            </div>
        @endif

        @if ($isOwner)
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="rounded-[var(--radius-control)] p-4 ring-1 ring-stone-200">
                    <p class="font-medium text-stone-900">{{ __('Clear all entries') }}</p>
                    <p class="mt-1 text-sm text-stone-600">{{ __('Deletes all sales, production, purchases, payments, wages, settlements, trolley costs and expenses. Every balance and stock goes back to 0.') }}</p>
                    <p class="mt-2 text-sm text-stone-600"><span class="font-medium text-stone-800">{{ __('Keeps') }}:</span> {{ __('settings, products, materials, rates, users, and the lists of parties, workers, trolleys and cash/bank accounts.') }}</p>
                    <x-ui.button class="mt-4" variant="danger" icon="eraser" wire:click="openReset('clear_entries')">{{ __('Clear all entries') }}</x-ui.button>
                </div>
                <div class="rounded-[var(--radius-control)] p-4 ring-1 ring-red-200">
                    <p class="font-medium text-red-800">{{ __('Factory reset') }}</p>
                    <p class="mt-1 text-sm text-stone-600">{{ __('Deletes everything of this kiln: entries, parties, workers, trolleys, accounts, products, materials and settings. Other users lose access. The default settings are created again, like a fresh start.') }}</p>
                    <p class="mt-2 text-sm text-stone-600"><span class="font-medium text-stone-800">{{ __('Keeps') }}:</span> {{ __('the kiln profile and your own login.') }}</p>
                    <x-ui.button class="mt-4" variant="danger" icon="triangle-alert" wire:click="openReset('factory')">{{ __('Factory reset') }}</x-ui.button>
                </div>
            </div>
        @else
            <p class="mt-4 text-sm text-stone-500">{{ __('Only the owner can reset data.') }}</p>
        @endif
    </x-ui.card>

    <x-ui.modal wire:model="showReset" :title="$resetMode === 'factory' ? __('Factory reset') : __('Clear all entries')" max-width="lg">
        <form id="reset-form" wire:submit="runReset" class="space-y-4">
            <div class="flex items-start gap-2 rounded-[var(--radius-control)] bg-red-50 px-3 py-2 text-sm text-red-800 ring-1 ring-red-200" role="alert">
                <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <p>{{ $resetMode === 'factory'
                    ? __('Everything of this kiln except its profile and your login will be deleted.')
                    : __('All entries will be deleted and every balance and stock set to 0.') }}
                    {{ __('A backup is made first; nothing is deleted if it fails.') }}</p>
            </div>
            @error('mode')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <x-ui.field :label="__('Your password')" for="reset-password" error="password" required>
                <x-ui.input id="reset-password" type="password" wire:model="password" autocomplete="current-password" />
            </x-ui.field>
            <x-ui.field :label="__('Type :phrase to confirm', ['phrase' => $phraseToType])" for="reset-phrase" error="phrase" required>
                <x-ui.input id="reset-phrase" wire:model="phrase" dir="ltr" autocomplete="off" :placeholder="$phraseToType" />
            </x-ui.field>
            <p wire:loading wire:target="runReset" class="text-sm text-stone-600" role="status">{{ __('Making the backup and resetting… this can take a minute. Keep this page open.') }}</p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="reset-form" variant="danger" icon="check" loading="runReset">{{ $resetMode === 'factory' ? __('Factory reset') : __('Clear all entries') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
