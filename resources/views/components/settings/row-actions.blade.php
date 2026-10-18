{{-- Edit + Archive/Restore buttons for one master-data row. --}}
@props(['record', 'editAction' => 'edit', 'archiveAction' => 'archive', 'restoreAction' => 'restore', 'canArchive' => true])

<div class="flex items-center justify-end gap-1">
    <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="{{ $editAction }}({{ $record->id }})" :aria-label="__('Edit :name', ['name' => $record->displayName()])">
        <span class="hidden xl:inline">{{ __('Edit') }}</span>
    </x-ui.button>
    @if ($record->is_active)
        @if ($canArchive)
            <x-ui.button
                size="sm"
                variant="ghost"
                icon="archive"
                wire:click="{{ $archiveAction }}({{ $record->id }})"
                wire:confirm="{{ __('Archive :name? It will no longer appear in new entries. Old records stay unchanged.', ['name' => $record->displayName()]) }}"
                :aria-label="__('Archive :name', ['name' => $record->displayName()])"
                :title="__('Archive')"
            />
        @endif
    @else
        <x-ui.button size="sm" variant="ghost" icon="archive-restore" wire:click="{{ $restoreAction }}({{ $record->id }})" :aria-label="__('Restore :name', ['name' => $record->displayName()])" :title="__('Restore')" />
    @endif
</div>
