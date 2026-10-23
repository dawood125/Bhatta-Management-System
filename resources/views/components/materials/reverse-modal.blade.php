{{--
    The reverse dialog of a component using ReversesMaterialEntries. Only for people allowed to
    reverse. Usage: <x-materials.reverse-modal :number="$this->reversingNumber()" />
--}}
@props(['number' => null])

@can(\App\Enums\Permission::TransactionsReverse->value)
    <x-ui.modal wire:model="showReverseForm" :title="__('Reverse entry')" :description="$number ? __('A mirror entry will cancel :number and return its stock. Both stay in the history.', ['number' => $number]) : null" max-width="md">
        <form id="material-reverse-form" wire:submit="reverse" class="space-y-4">
            <x-ui.field :label="__('Reason')" for="material-reverse-reason" error="reverseForm.reason" required :hint="__('For example: wrong amount, entered twice.')">
                <x-ui.input id="material-reverse-reason" wire:model="reverseForm.reason" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="material-reverse-form" variant="danger" icon="undo-2" loading="reverse">{{ __('Reverse') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endcan
