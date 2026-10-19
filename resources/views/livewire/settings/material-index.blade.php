<x-settings.shell :title="__('Materials')" :description="__('Materials the kiln buys and uses, such as soil, Kassu, coal and Bora. A material\'s unit is fixed once it has stock entries.')">
    <x-slot:actions>
        <x-ui.button icon="plus" wire:click="create">{{ __('Add material') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <x-settings.toolbar />
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th>{{ __('Unit') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->records as $material)
            <tr wire:key="material-{{ $material->id }}" @class(['transition-colors hover:bg-stone-50/70', 'opacity-60' => ! $material->is_active]) wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
                <x-ui.td><x-settings.name-cell :record="$material" /></x-ui.td>
                <x-ui.td><x-ui.badge :tone="$material->kind->tone()">{{ $material->kind->label() }}</x-ui.badge></x-ui.td>
                <x-ui.td class="whitespace-nowrap">{{ $material->unit->displayName() }}</x-ui.td>
                <x-ui.td><x-settings.status-badge :active="$material->is_active" /></x-ui.td>
                <x-ui.td align="end"><x-settings.row-actions :record="$material" /></x-ui.td>
            </tr>
        @empty
            <tr><td colspan="5"><x-ui.empty-state icon="package" :title="__('Nothing found')" :description="__('Try a different search or filter.')" /></td></tr>
        @endforelse

        @if ($this->records->hasPages())
            <x-slot:footer>{{ $this->records->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit material') : __('Add material')">
        <form id="material-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-settings.name-fields id="material" />

            <x-ui.field :label="__('Type')" for="material-kind" error="form.kind" required :hint="__('Fuels are offered when recording firing.')">
                <x-ui.select id="material-kind" wire:model="form.kind" :options="\App\Enums\MaterialKind::options()" />
            </x-ui.field>

            <x-ui.field :label="__('Unit')" for="material-unit" error="form.unit_id" required :hint="__('How it is bought and counted, e.g. ton, cubic foot, trolley.')">
                <x-ui.select id="material-unit" wire:model="form.unit_id" :placeholder="__('Choose a unit')" :options="$this->unitOptions" />
            </x-ui.field>

            <x-ui.field :label="__('Display order')" for="material-order" error="form.sort_order" required :hint="__('Smaller numbers appear first in lists.')">
                <x-ui.input id="material-order" type="number" min="0" max="9999" step="1" wire:model="form.sort_order" dir="ltr" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="material-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-settings.shell>
