{{-- English + Urdu name inputs bound to "{prefix}.name" and "{prefix}.name_ur". --}}
@props(['prefix' => 'form', 'id' => 'form'])

<x-ui.field :label="__('Name (English)')" for="{{ $id }}-name" error="{{ $prefix }}.name" required>
    <x-ui.input id="{{ $id }}-name" wire:model="{{ $prefix }}.name" lang="en" dir="ltr" autocomplete="off" />
</x-ui.field>

<x-ui.field :label="__('Name (Urdu)')" for="{{ $id }}-name-ur" error="{{ $prefix }}.name_ur" :hint="__('Shown when the language is Urdu.')">
    <x-ui.input id="{{ $id }}-name-ur" wire:model="{{ $prefix }}.name_ur" lang="ur" dir="rtl" autocomplete="off" />
</x-ui.field>
