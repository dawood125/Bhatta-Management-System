<x-settings.shell :title="__('Units')" :description="__('Units used to count and measure products and materials.')">
    <x-slot:actions>
        <x-ui.button icon="plus" wire:click="create">{{ __('Add unit') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <x-settings.toolbar />
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Symbol') }}</x-ui.th>
            <x-ui.th>{{ __('Decimal places') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->records as $unit)
            <tr wire:key="unit-{{ $unit->id }}" @class(['transition-colors hover:bg-stone-50/70', 'opacity-60' => ! $unit->is_active]) wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
                <x-ui.td>
                    <div class="flex items-center gap-2">
                        <x-settings.name-cell :record="$unit" />
                        @if ($unit->is_system)
                            <x-ui.badge tone="info" icon="lock">{{ __('Built-in') }}</x-ui.badge>
                        @endif
                    </div>
                </x-ui.td>
                <x-ui.td><span class="tabular" dir="ltr">{{ $unit->symbol ?? '—' }}</span></x-ui.td>
                <x-ui.td numeric>{{ $unit->decimals }}</x-ui.td>
                <x-ui.td><x-settings.status-badge :active="$unit->is_active" /></x-ui.td>
                <x-ui.td align="end"><x-settings.row-actions :record="$unit" :can-archive="! $unit->is_system" /></x-ui.td>
            </tr>
        @empty
            <tr><td colspan="5"><x-ui.empty-state icon="ruler" :title="__('Nothing found')" :description="__('Try a different search or filter.')" /></td></tr>
        @endforelse

        @if ($this->records->hasPages())
            <x-slot:footer>{{ $this->records->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit unit') : __('Add unit')">
        <form id="unit-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-settings.name-fields id="unit" />

            <x-ui.field :label="__('Symbol')" for="unit-symbol" error="form.symbol" :hint="__('Short form, e.g. t, kg, cft.')">
                <x-ui.input id="unit-symbol" wire:model="form.symbol" dir="ltr" />
            </x-ui.field>

            <x-ui.field :label="__('Decimal places')" for="unit-decimals" error="form.decimals" required :hint="$this->editingSystemUnit() ? __('Fixed for built-in units.') : __('0 for whole numbers (e.g. bricks), 3 for tons.')">
                <x-ui.select id="unit-decimals" wire:model="form.decimals" :options="['0' => '0', '1' => '1', '2' => '2', '3' => '3']" :disabled="$this->editingSystemUnit()" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="unit-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-settings.shell>
