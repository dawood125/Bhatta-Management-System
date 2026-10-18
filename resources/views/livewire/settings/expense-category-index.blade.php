<x-settings.shell :title="__('Expense categories')" :description="__('Groups for day-to-day kiln expenses. Material purchases and wages are recorded in their own modules, not here.')">
    <x-slot:actions>
        <x-ui.button icon="plus" wire:click="create">{{ __('Add category') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <x-settings.toolbar />
        </div>

        <ul class="divide-y divide-stone-100" wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
            @forelse ($this->records as $category)
                <li wire:key="category-{{ $category->id }}" @class(['p-4 sm:px-5', 'opacity-60' => ! $category->is_active])>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <x-settings.name-cell :record="$category" />
                        </div>
                        <x-settings.status-badge :active="$category->is_active" />
                        <x-settings.row-actions :record="$category" />
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 ps-0 sm:ps-4">
                        @foreach ($category->subCategories as $sub)
                            <span wire:key="sub-{{ $sub->id }}" @class([
                                'inline-flex items-center gap-1 rounded-lg py-1 ps-2.5 pe-1 text-sm ring-1 ring-inset',
                                'bg-stone-50 text-stone-700 ring-stone-200' => $sub->is_active,
                                'bg-white text-stone-400 line-through ring-stone-200' => ! $sub->is_active,
                            ])>
                                {{ $sub->displayName() }}
                                <button type="button" wire:click="editSub({{ $sub->id }})" class="rounded p-1 text-stone-400 hover:bg-stone-200/70 hover:text-stone-700" aria-label="{{ __('Edit :name', ['name' => $sub->displayName()]) }}">
                                    <x-lucide-pencil class="size-3.5" aria-hidden="true" />
                                </button>
                                @if ($sub->is_active)
                                    <button type="button" wire:click="archiveSub({{ $sub->id }})" wire:confirm="{{ __('Archive :name? It will no longer appear in new entries. Old records stay unchanged.', ['name' => $sub->displayName()]) }}" class="rounded p-1 text-stone-400 hover:bg-stone-200/70 hover:text-stone-700" aria-label="{{ __('Archive :name', ['name' => $sub->displayName()]) }}">
                                        <x-lucide-archive class="size-3.5" aria-hidden="true" />
                                    </button>
                                @else
                                    <button type="button" wire:click="restoreSub({{ $sub->id }})" class="rounded p-1 text-stone-400 hover:bg-stone-200/70 hover:text-stone-700" aria-label="{{ __('Restore :name', ['name' => $sub->displayName()]) }}">
                                        <x-lucide-archive-restore class="size-3.5" aria-hidden="true" />
                                    </button>
                                @endif
                            </span>
                        @endforeach

                        @if ($category->is_active)
                            <button type="button" wire:click="createSub({{ $category->id }})" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-sm font-medium text-brand-700 hover:bg-brand-50">
                                <x-lucide-plus class="size-4" aria-hidden="true" /> {{ __('Add sub-category') }}
                            </button>
                        @endif
                    </div>
                </li>
            @empty
                <li><x-ui.empty-state icon="tags" :title="__('Nothing found')" :description="__('Try a different search or filter.')" /></li>
            @endforelse
        </ul>

        @if ($this->records->hasPages())
            <div class="border-t border-stone-200 px-4 py-3">{{ $this->records->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit category') : __('Add category')" max-width="md">
        <form id="category-form" wire:submit="save" class="space-y-4">
            <x-settings.name-fields id="category" />
            <x-ui.field :label="__('Display order')" for="category-order" error="form.sort_order" required :hint="__('Smaller numbers appear first in lists.')">
                <x-ui.input id="category-order" type="number" min="0" max="9999" step="1" wire:model="form.sort_order" dir="ltr" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="category-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showSubForm" :title="$subEditingId ? __('Edit sub-category') : __('Add sub-category')" max-width="md">
        <form id="sub-category-form" wire:submit="saveSub" class="space-y-4">
            <x-settings.name-fields prefix="subForm" id="sub-category" />
            @error('subForm.expense_category_id')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="sub-category-form" icon="check" loading="saveSub">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-settings.shell>
