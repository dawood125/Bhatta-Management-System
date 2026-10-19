{{-- From/to dates and quick ranges for a Livewire component using FiltersByDateRange. --}}
@props(['id' => 'range'])

<div {{ $attributes->class('flex flex-col gap-3 lg:flex-row lg:items-end') }}>
    <div class="grid grid-cols-2 gap-3 sm:w-80">
        <div>
            <label for="{{ $id }}-from" class="mb-1 block text-xs font-medium text-stone-500">{{ __('From date') }}</label>
            <x-ui.input id="{{ $id }}-from" type="date" wire:model.live="from" dir="ltr" />
        </div>
        <div>
            <label for="{{ $id }}-to" class="mb-1 block text-xs font-medium text-stone-500">{{ __('To date') }}</label>
            <x-ui.input id="{{ $id }}-to" type="date" wire:model.live="to" dir="ltr" />
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <x-ui.button size="sm" variant="secondary" wire:click="setRange('today')">{{ __('Today') }}</x-ui.button>
        <x-ui.button size="sm" variant="secondary" wire:click="setRange('this_month')">{{ __('This month') }}</x-ui.button>
        <x-ui.button size="sm" variant="secondary" wire:click="setRange('last_month')">{{ __('Last month') }}</x-ui.button>
        <x-ui.button size="sm" variant="secondary" wire:click="setRange('this_year')">{{ __('This year') }}</x-ui.button>
    </div>
</div>
