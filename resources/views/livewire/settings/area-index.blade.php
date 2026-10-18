<x-settings.shell :title="__('Areas')" :description="__('Towns, villages or routes your customers come from. Used to group customers and reports.')">
    <x-slot:actions>
        <x-ui.button icon="plus" wire:click="create">{{ __('Add area') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <x-settings.toolbar />
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->records as $area)
            <tr wire:key="area-{{ $area->id }}" @class(['transition-colors hover:bg-stone-50/70', 'opacity-60' => ! $area->is_active]) wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
                <x-ui.td><x-settings.name-cell :record="$area" /></x-ui.td>
                <x-ui.td><x-settings.status-badge :active="$area->is_active" /></x-ui.td>
                <x-ui.td align="end"><x-settings.row-actions :record="$area" /></x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="3">
                    <x-ui.empty-state icon="map-pin" :title="$search === '' && $status === 'active' ? __('No areas yet') : __('Nothing found')" :description="$search === '' && $status === 'active' ? __('Add the towns or routes your customers come from.') : __('Try a different search or filter.')">
                        @if ($search === '' && $status === 'active')
                            <x-ui.button icon="plus" variant="secondary" wire:click="create">{{ __('Add area') }}</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </td>
            </tr>
        @endforelse

        @if ($this->records->hasPages())
            <x-slot:footer>{{ $this->records->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit area') : __('Add area')" max-width="md">
        <form id="area-form" wire:submit="save" class="space-y-4">
            <x-settings.name-fields id="area" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="area-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-settings.shell>
