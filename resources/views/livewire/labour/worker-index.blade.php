<div>
    <x-ui.page-header :title="__('Labour')" :description="__('Workers, what each one has earned and taken, and what is still due.')">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button icon="user-plus" wire:click="create">{{ __('Add worker') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-labour.tabs active="workers" />

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-2xl">
        <x-ui.card class="py-4!">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Wages due') }}</p>
            <p class="mt-1 text-lg font-semibold text-amber-800"><x-ui.money :amount="$this->totals['owed_to_workers']" /></p>
            <p class="text-xs text-stone-500">{{ __('What the kiln owes its workers') }}</p>
        </x-ui.card>
        <x-ui.card class="py-4!">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Advances out') }}</p>
            <p class="mt-1 text-lg font-semibold text-sky-800"><x-ui.money :amount="$this->totals['advances_out']" /></p>
            <p class="text-xs text-stone-500">{{ __('What workers owe the kiln') }}</p>
        </x-ui.card>
    </div>

    <x-ui.table>
        <x-slot:toolbar>
            <div class="w-full sm:max-w-xs">
                <label for="worker-search" class="sr-only">{{ __('Search') }}</label>
                <x-ui.input id="worker-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search name or phone')" />
            </div>
            <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                <div class="sm:w-52">
                    <label for="worker-category" class="sr-only">{{ __('Category') }}</label>
                    <x-ui.select id="worker-category" wire:model.live="category" :placeholder="__('All categories')" :options="$this->categoryOptions" />
                </div>
                <div class="sm:w-36">
                    <label for="worker-status" class="sr-only">{{ __('Status') }}</label>
                    <x-ui.select id="worker-status" wire:model.live="status" :options="['active' => __('Active'), 'archived' => __('Archived'), 'all' => __('All')]" />
                </div>
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th class="min-w-56">{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Category') }}</x-ui.th>
            <x-ui.th>{{ __('Phone') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->workers as $worker)
            <tr wire:key="worker-{{ $worker->id }}" @class(['transition-colors hover:bg-stone-50/70', 'opacity-60' => ! $worker->is_active]) wire:loading.class="opacity-60" wire:target="category,search,status,gotoPage,nextPage,previousPage">
                <x-ui.td>
                    <a href="{{ route('labour.workers.show', $worker) }}" wire:navigate class="hover:underline"><x-settings.name-cell :record="$worker" /></a>
                    @if ($worker->joined_on)
                        <p class="text-xs text-stone-500">{{ __('Joined on :date', ['date' => $worker->joined_on->format('d-m-Y')]) }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td>
                    <p class="font-medium text-stone-800">{{ $worker->category->displayName() }}</p>
                    <p class="text-xs text-stone-500">{{ $worker->category->basis->label() }}</p>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $worker->phone ?? '—' }}</span></x-ui.td>
                <x-ui.td align="end"><x-worker-balance :amount="$this->balances[$worker->id]" /></x-ui.td>
                <x-ui.td align="end">
                    <div class="flex items-center justify-end gap-1">
                        <x-ui.button size="sm" variant="ghost" icon="book-open" :href="route('labour.workers.show', $worker)" wire:navigate :aria-label="__('Account of :name', ['name' => $worker->displayName()])" :title="__('Account')" />
                        @if ($canManage)
                            <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $worker->id }})" :aria-label="__('Edit :name', ['name' => $worker->displayName()])" :title="__('Edit')" />
                            @if ($worker->is_active)
                                <x-ui.button size="sm" variant="ghost" icon="archive" wire:click="archive({{ $worker->id }})" wire:confirm="{{ __('Archive :name? It will no longer appear in new entries. Old records stay unchanged.', ['name' => $worker->displayName()]) }}" :aria-label="__('Archive :name', ['name' => $worker->displayName()])" :title="__('Archive')" />
                            @else
                                <x-ui.button size="sm" variant="ghost" icon="archive-restore" wire:click="restore({{ $worker->id }})" :aria-label="__('Restore :name', ['name' => $worker->displayName()])" :title="__('Restore')" />
                            @endif
                        @endif
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty-state icon="hard-hat" :title="__('No workers found')" :description="$search !== '' || $category !== '' ? __('Try a different search or filter.') : __('Add the Pathai families, Kamyaar, Nakaasi, salaried staff and contractors who work at the kiln.')">
                        @if ($canManage && $search === '' && $category === '')
                            <x-ui.button icon="user-plus" variant="secondary" wire:click="create">{{ __('Add worker') }}</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </td>
            </tr>
        @endforelse

        @if ($this->workers->hasPages())
            <x-slot:footer>{{ $this->workers->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    @if ($canManage)
        <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit worker') : __('Add worker')" max-width="lg">
            <form id="worker-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Name (English)')" for="worker-name" error="form.name" required :hint="__('A family or team can be one worker, named after its head.')">
                    <x-ui.input id="worker-name" wire:model.live.debounce.400ms="form.name" lang="en" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Name (Urdu)')" for="worker-name-ur" error="form.name_ur" :hint="__('Shown when the language is Urdu.')">
                    <x-ui.input id="worker-name-ur" wire:model="form.name_ur" lang="ur" dir="rtl" autocomplete="off" />
                </x-ui.field>

                @if ($showForm && $this->similarWorkers->isNotEmpty())
                    <div class="flex items-start gap-2 rounded-[var(--radius-control)] bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 sm:col-span-2" role="status">
                        <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-medium">{{ __('Similar names already exist. Make sure this is not the same person:') }}</p>
                            <p>{{ $this->similarWorkers->map(fn ($w) => $w->displayName().' ('.$w->category->displayName().')')->implode('، ') }}</p>
                        </div>
                    </div>
                @endif

                <x-ui.field :label="__('Category')" for="worker-category-select" error="form.labour_category_id" required :hint="__('Decides how the worker is paid.')" class="sm:col-span-2">
                    <x-ui.select id="worker-category-select" wire:model="form.labour_category_id" :placeholder="__('Choose a category')" :options="$this->formCategoryOptions" />
                </x-ui.field>

                <x-ui.field :label="__('Phone')" for="worker-phone" error="form.phone" :hint="__('Optional')">
                    <x-ui.input id="worker-phone" wire:model="form.phone" inputmode="tel" dir="ltr" placeholder="03XX XXXXXXX" />
                </x-ui.field>
                <x-ui.field :label="__('Joined on')" for="worker-joined" error="form.joined_on" :hint="__('For salaries: days before it are not paid.')">
                    <x-ui.input id="worker-joined" type="date" wire:model="form.joined_on" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                <x-ui.field :label="__('Note')" for="worker-notes" error="form.notes" :hint="__('Optional')" class="sm:col-span-2">
                    <x-ui.input id="worker-notes" wire:model="form.notes" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="worker-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
