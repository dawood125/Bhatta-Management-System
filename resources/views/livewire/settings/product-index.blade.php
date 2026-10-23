<x-settings.shell :title="__('Products and grades')" :description="__('Brick grades that come out of Nakaasi, raw bricks, and by-products. Grades are counted in bricks.')">
    <x-slot:actions>
        <x-ui.button icon="plus" wire:click="create">{{ __('Add product') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <x-settings.toolbar />
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th>{{ __('Unit') }}</x-ui.th>
            <x-ui.th>{{ __('For sale') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->records as $product)
            <tr wire:key="product-{{ $product->id }}" @class(['transition-colors hover:bg-stone-50/70', 'opacity-60' => ! $product->is_active]) wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
                <x-ui.td><x-settings.name-cell :record="$product" /></x-ui.td>
                <x-ui.td>
                    <x-ui.badge :tone="match ($product->kind) { \App\Enums\ProductKind::FiredGrade => 'brand', \App\Enums\ProductKind::RawBrick => 'warning', default => 'neutral' }">{{ $product->kind->label() }}</x-ui.badge>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap">{{ $product->unit->displayName() }}</x-ui.td>
                <x-ui.td>
                    @if ($product->is_saleable)
                        <span class="inline-flex items-center gap-1 text-emerald-700"><x-lucide-check class="size-4" aria-hidden="true" /> {{ __('Yes') }}</span>
                    @else
                        <span class="text-stone-500">{{ __('No') }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td><x-settings.status-badge :active="$product->is_active" /></x-ui.td>
                <x-ui.td align="end"><x-settings.row-actions :record="$product" /></x-ui.td>
            </tr>
        @empty
            <tr><td colspan="6"><x-ui.empty-state icon="brick-wall" :title="__('Nothing found')" :description="__('Try a different search or filter.')" /></td></tr>
        @endforelse

        @if ($this->records->hasPages())
            <x-slot:footer>{{ $this->records->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit product') : __('Add product')">
        <form id="product-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-settings.name-fields id="product" />

            <x-ui.field :label="__('Type')" for="product-kind" error="form.kind" required>
                <x-ui.select id="product-kind" wire:model.live="form.kind" :options="\App\Enums\ProductKind::options()" />
            </x-ui.field>

            <x-ui.field :label="__('Unit')" for="product-unit" error="form.unit_id" required :hint="($form['kind'] ?? '') !== 'by_product' ? __('Brick grades and raw bricks must be counted in bricks.') : null">
                <x-ui.select id="product-unit" wire:model="form.unit_id" :options="$this->unitOptions" />
            </x-ui.field>

            <x-ui.field :label="__('Display order')" for="product-order" error="form.sort_order" required :hint="__('Smaller numbers appear first in lists.')">
                <x-ui.input id="product-order" type="number" min="0" max="9999" step="1" wire:model="form.sort_order" dir="ltr" />
            </x-ui.field>

            <div class="flex items-center sm:pt-7">
                <x-ui.checkbox id="product-saleable" wire:model="form.is_saleable" :label="__('Can be sold')" />
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="product-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-settings.shell>
