{{-- Search box + Active / Archived / All filter for master-data lists. --}}
<div class="w-full sm:max-w-xs">
    <label for="settings-search" class="sr-only">{{ __('Search') }}</label>
    <x-ui.input id="settings-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search by name')" />
</div>
<div class="w-full sm:w-40">
    <label for="settings-status" class="sr-only">{{ __('Status') }}</label>
    <x-ui.select id="settings-status" wire:model.live="status" :options="['active' => __('Active'), 'archived' => __('Archived'), 'all' => __('All')]" />
</div>
