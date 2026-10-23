@php use App\Enums\PayBasis; @endphp
<div>
    <x-ui.page-header :title="__('Labour')" :description="__('How each kind of labour is paid, and the rate card with the date each rate starts.')">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button icon="plus" wire:click="createCategory">{{ __('Add category') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-labour.tabs active="rates" />

    <div class="mb-4 flex justify-end">
        <div class="w-full sm:w-40">
            <label for="rates-status" class="sr-only">{{ __('Status') }}</label>
            <x-ui.select id="rates-status" wire:model.live="status" :options="['active' => __('Active'), 'archived' => __('Archived'), 'all' => __('All')]" />
        </div>
    </div>

    @if ($this->categories->isEmpty())
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="tags" :title="__('No labour categories')" :description="__('Add the kinds of labour the kiln pays, e.g. Pathai by the 1,000 bricks or salaried staff.')">
                @if ($canManage)
                    <x-ui.button icon="plus" variant="secondary" wire:click="createCategory">{{ __('Add category') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" wire:loading.class="opacity-60" wire:target="status">
            @foreach ($this->categories as $category)
                @php($rates = $this->ratesByCategory[$category->id] ?? [])
                @php($categoryRates = array_values(array_filter($rates, fn ($r) => $r->worker_id === null)))
                @php($workerRates = array_values(array_filter($rates, fn ($r) => $r->worker_id !== null)))
                @php($current = collect($categoryRates)->first(fn ($r) => $r->effective_from->lte(today())))
                <x-ui.card :padding="false" wire:key="category-{{ $category->id }}" @class(['opacity-60' => ! $category->is_active])>
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 p-4">
                        <div class="min-w-0">
                            <x-settings.name-cell :record="$category" />
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                <x-ui.badge tone="brand">{{ $category->basis->label() }}</x-ui.badge>
                                @if ($category->activity)
                                    <x-ui.badge tone="info">{{ $category->activity->label() }}</x-ui.badge>
                                @endif
                                @if (! $category->is_active)
                                    <x-ui.badge>{{ __('Archived') }}</x-ui.badge>
                                @endif
                                <span class="text-xs text-stone-500">{{ trans_choice('{0} No workers|{1} One worker|[2,*] :count workers', $category->workers_count, ['count' => $category->workers_count]) }}</span>
                            </div>
                        </div>
                        @if ($canManage)
                            <div class="flex shrink-0 items-center gap-1">
                                <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="editCategory({{ $category->id }})" :aria-label="__('Edit :name', ['name' => $category->displayName()])" :title="__('Edit')" />
                                @if ($category->is_active)
                                    <x-ui.button size="sm" variant="ghost" icon="archive" wire:click="archiveCategory({{ $category->id }})" wire:confirm="{{ __('Archive :name? It will no longer appear in new entries. Old records stay unchanged.', ['name' => $category->displayName()]) }}" :aria-label="__('Archive :name', ['name' => $category->displayName()])" :title="__('Archive')" />
                                @else
                                    <x-ui.button size="sm" variant="ghost" icon="archive-restore" wire:click="restoreCategory({{ $category->id }})" :aria-label="__('Restore :name', ['name' => $category->displayName()])" :title="__('Restore')" />
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="space-y-4 p-4">
                        @if (! $category->basis->hasRate())
                            <p class="text-sm text-stone-600">{{ __('Paid in agreed amounts recorded on the worker\'s account; no rate card.') }}</p>
                        @else
                            <div>
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-medium text-stone-700">{{ __('Category rate') }} <span class="font-normal text-stone-500">({{ $category->basis->rateUnit() }})</span></h3>
                                    @if ($canManage && $category->is_active)
                                        <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="createRate({{ $category->id }})">{{ __('New rate') }}</x-ui.button>
                                    @endif
                                </div>
                                @if ($categoryRates === [])
                                    <p class="text-sm text-amber-700">{{ $category->basis === PayBasis::PerMonth ? __('No common salary. Set each worker\'s own salary below.') : __('No rate yet. Wages cannot be worked out until one is set.') }}</p>
                                @else
                                    <ul class="divide-y divide-stone-100 rounded-[var(--radius-control)] ring-1 ring-stone-200">
                                        @foreach ($categoryRates as $rate)
                                            <li wire:key="rate-{{ $rate->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <x-ui.money :amount="$rate->rate" class="font-semibold text-stone-900" />
                                                    <span class="text-stone-500">{{ __('from :date', ['date' => $rate->effective_from->format('d-m-Y')]) }}</span>
                                                    @if ($current && $current->id === $rate->id)
                                                        <x-ui.badge tone="success">{{ __('In use') }}</x-ui.badge>
                                                    @elseif ($rate->effective_from->isAfter(today()))
                                                        <x-ui.badge tone="info">{{ __('Upcoming') }}</x-ui.badge>
                                                    @endif
                                                </div>
                                                @if ($canManage && ! isset($this->usedRates[$rate->id]))
                                                    <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeRate({{ $rate->id }})" wire:confirm="{{ __('Remove this rate? No wages were worked out with it.') }}" :aria-label="__('Remove rate from :date', ['date' => $rate->effective_from->format('d-m-Y')])" :title="__('Remove')" />
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            <div>
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-medium text-stone-700">{{ $category->basis === PayBasis::PerMonth ? __('Salaries of workers') : __('Workers\' own rates') }}</h3>
                                    @if ($canManage && $category->is_active && $category->workers_count > 0)
                                        <x-ui.button size="sm" variant="ghost" icon="plus" wire:click="createRate({{ $category->id }}, true)">{{ __('Own rate for a worker') }}</x-ui.button>
                                    @endif
                                </div>
                                @if ($workerRates === [])
                                    <p class="text-sm text-stone-500">{{ __('None. Every worker gets the category rate.') }}</p>
                                @else
                                    <ul class="divide-y divide-stone-100 rounded-[var(--radius-control)] ring-1 ring-stone-200">
                                        @foreach ($workerRates as $rate)
                                            <li wire:key="rate-{{ $rate->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                                    <span class="font-medium text-stone-800">{{ $rate->worker?->displayName() }}</span>
                                                    <x-ui.money :amount="$rate->rate" class="font-semibold text-stone-900" />
                                                    <span class="text-stone-500">{{ __('from :date', ['date' => $rate->effective_from->format('d-m-Y')]) }}</span>
                                                </div>
                                                @if ($canManage && ! isset($this->usedRates[$rate->id]))
                                                    <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeRate({{ $rate->id }})" wire:confirm="{{ __('Remove this rate? No wages were worked out with it.') }}" :aria-label="__('Remove rate of :name from :date', ['name' => $rate->worker?->displayName(), 'date' => $rate->effective_from->format('d-m-Y')])" :title="__('Remove')" />
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-stone-500">{{ __('A new rate applies to work from its date on; earlier work keeps the rate of its day. A rate stays once wages were worked out with it.') }}</p>
    @endif

    @if ($canManage)
        <x-ui.modal wire:model="showCategoryForm" :title="$editingId ? __('Edit category') : __('Add category')" max-width="lg">
            <form id="category-form" wire:submit="saveCategory" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Name (English)')" for="category-name" error="categoryForm.name" required>
                    <x-ui.input id="category-name" wire:model="categoryForm.name" lang="en" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Name (Urdu)')" for="category-name-ur" error="categoryForm.name_ur" :hint="__('Shown when the language is Urdu.')">
                    <x-ui.input id="category-name-ur" wire:model="categoryForm.name_ur" lang="ur" dir="rtl" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('How paid')" for="category-basis" error="categoryForm.basis" required>
                    <x-ui.select id="category-basis" wire:model.live="categoryForm.basis" :options="PayBasis::options()" />
                </x-ui.field>
                @if (($categoryForm['basis'] ?? '') === PayBasis::PerThousand->value)
                    <x-ui.field :label="__('Paid for')" for="category-activity" error="categoryForm.activity" :hint="__('Wages are then earned from that production entry.')">
                        <x-ui.select id="category-activity" wire:model="categoryForm.activity" :placeholder="__('Recorded by hand')" :options="\App\Enums\LabourActivity::options()" />
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('Display order')" for="category-order" error="categoryForm.sort_order" required>
                    <x-ui.input id="category-order" type="number" min="0" max="9999" wire:model="categoryForm.sort_order" dir="ltr" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="category-form" icon="check" loading="saveCategory">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal wire:model="showRateForm" :title="$rateForWorker ? __('Own rate for a worker') : __('New rate')" :description="$this->rateCategory ? $this->rateCategory->displayName().' · '.$this->rateCategory->basis->label() : null" max-width="md">
            <form id="rate-form" wire:submit="saveRate" class="grid gap-4 sm:grid-cols-2">
                @if ($rateForWorker)
                    <x-ui.field :label="__('Worker')" for="rate-worker" error="rateForm.worker_id" required class="sm:col-span-2">
                        <x-ui.select id="rate-worker" wire:model="rateForm.worker_id" :placeholder="__('Choose a worker')" :options="$this->rateWorkerOptions" />
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('Rate')" for="rate-amount" error="rateForm.rate" required :hint="$this->rateCategory?->basis->rateUnit()">
                    <x-ui.input id="rate-amount" wire:model="rateForm.rate" inputmode="decimal" :suffix="__('Rs')" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Starts on')" for="rate-from" error="rateForm.effective_from" required :hint="__('Work from this date on gets the new rate.')">
                    <x-ui.input id="rate-from" type="date" wire:model="rateForm.effective_from" dir="ltr" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="rate-form" icon="check" loading="saveRate">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
