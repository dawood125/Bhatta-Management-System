<x-settings.shell :title="__('Production stages')" :description="__('The stages bricks pass through. You can rename them to the words used at your kiln; the stages themselves and their order are fixed.')">
    <ol class="grid gap-3 md:grid-cols-2">
        @foreach ($this->stages as $stage)
            <li wire:key="stage-{{ $stage->id }}">
                <x-ui.card class="flex h-full items-start gap-4">
                    <span class="tabular flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">{{ $loop->iteration }}</span>
                    <div class="min-w-0 flex-1">
                        <x-settings.name-cell :record="$stage" />
                        <p class="mt-2 text-sm text-stone-500">{{ $stage->kind->meaning() }}</p>
                    </div>
                    <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $stage->id }})" :aria-label="__('Rename :name', ['name' => $stage->displayName()])" :title="__('Rename')" />
                </x-ui.card>
            </li>
        @endforeach
    </ol>

    <x-ui.modal wire:model="showForm" :title="__('Rename stage')" max-width="md">
        <form id="stage-form" wire:submit="save" class="space-y-4">
            <x-settings.name-fields id="stage" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="stage-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-settings.shell>
