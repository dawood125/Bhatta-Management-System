<div>
    <x-ui.page-header :title="__('Materials')" :description="__('Stock of soil, fuel and other materials, valued at their average cost.')">
        <x-slot:actions>
            @if ($canAdjust)
                <x-ui.button variant="secondary" icon="clipboard-pen" wire:click="openAdjust('opening')">{{ __('Opening stock / shortage') }}</x-ui.button>
            @endif
            @if ($canRecord)
                <x-ui.button variant="secondary" icon="flame" :href="route('materials.usage', ['new' => 1])" wire:navigate>{{ __('Record usage') }}</x-ui.button>
                <x-ui.button icon="truck" :href="route('materials.purchases.create')" wire:navigate>{{ __('New purchase') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-materials.tabs active="stock" />

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-2xl">
        <x-ui.card class="py-4!">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Value of material stock') }}</p>
            <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->totalValue" /></p>
            <p class="text-xs text-stone-500">{{ __('At average cost, including transport and other charges.') }}</p>
        </x-ui.card>
    </div>

    <x-ui.table>
        <x-slot:toolbar>
            <div class="flex w-full justify-end">
                <x-ui.checkbox id="materials-archived" wire:model.live="showArchived" :label="__('Show archived')" />
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th class="min-w-48">{{ __('Material') }}</x-ui.th>
            <x-ui.th align="end">{{ __('In stock') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Average cost') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Value') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->materials as $material)
            @php($level = $this->levels[$material->id])
            @php($average = $stock->averageCost($level))
            <tr wire:key="stock-{{ $material->id }}" class="transition-colors hover:bg-stone-50/70" wire:loading.class="opacity-60" wire:target="showArchived">
                <x-ui.td>
                    <a href="{{ route('materials.show', $material) }}" wire:navigate class="hover:underline"><x-settings.name-cell :record="$material" /></a>
                    <x-ui.badge :tone="$material->kind->tone()" class="mt-1">{{ $material->kind->label() }}</x-ui.badge>
                </x-ui.td>
                <x-ui.td align="end" class="whitespace-nowrap">
                    <span @class(['tabular font-semibold', 'text-stone-400' => $level['quantity']->isZero(), 'text-stone-900' => ! $level['quantity']->isZero()]) dir="ltr">{{ $level['quantity']->format($material->unit->decimals) }}</span>
                    <span class="text-xs text-stone-500">{{ $material->unit->symbol ?? $material->unit->displayName() }}</span>
                </x-ui.td>
                <x-ui.td align="end" class="whitespace-nowrap">
                    @if ($average)
                        <x-ui.money :amount="$average" />
                        <span class="text-xs text-stone-500">/ {{ $material->unit->symbol ?? $material->unit->displayName() }}</span>
                    @else
                        <span class="text-stone-400">—</span>
                    @endif
                </x-ui.td>
                <x-ui.td align="end" class="font-medium"><x-ui.money :amount="$level['value']" :muted="true" /></x-ui.td>
                <x-ui.td align="end">
                    <div class="flex items-center justify-end gap-1">
                        <x-ui.button size="sm" variant="ghost" icon="book-open" :href="route('materials.show', $material)" wire:navigate :aria-label="__('Stock card of :name', ['name' => $material->displayName()])" :title="__('Stock card')" />
                        @if ($canAdjust && $material->is_active && $level['quantity']->isPositive())
                            <x-ui.button size="sm" variant="ghost" icon="package-minus" wire:click="openAdjust('shortage', {{ $material->id }})" :aria-label="__('Record a shortage of :name', ['name' => $material->displayName()])" :title="__('Shortage')" />
                        @endif
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty-state icon="package" :title="$showArchived ? __('No archived materials') : __('No materials yet')" :description="__('Add soil, coal and other materials in Kiln settings.')" />
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    @if ($canAdjust)
        <x-ui.modal wire:model="showAdjustForm" :title="__('Opening stock or shortage')" max-width="lg">
            <form id="adjust-form" wire:submit="saveAdjust" class="grid gap-4 sm:grid-cols-2">
                <fieldset class="sm:col-span-2">
                    <legend class="mb-1.5 text-sm font-medium text-stone-700">{{ __('What happened') }}</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (['opening' => [__('Opening stock'), __('Material already at the kiln when you start using the system.')], 'shortage' => [__('Shortage / wastage'), __('Less found when counting, or spoiled. Valued at average cost.')]] as $value => [$title, $help])
                            <label @class([
                                'flex cursor-pointer gap-3 rounded-[var(--radius-control)] border p-3 text-sm transition',
                                'border-brand-500 bg-brand-50' => ($adjustForm['kind'] ?? '') === $value,
                                'border-stone-300 hover:border-stone-400' => ($adjustForm['kind'] ?? '') !== $value,
                            ])>
                                <input type="radio" value="{{ $value }}" wire:model.live="adjustForm.kind" class="mt-0.5 size-4 accent-brand-600">
                                <span><span class="block font-medium text-stone-900">{{ $title }}</span><span class="block text-xs text-stone-500">{{ $help }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <x-ui.field :label="__('Material')" for="adjust-material" error="adjustForm.material_id" required>
                    <x-ui.select id="adjust-material" wire:model.live="adjustForm.material_id" :placeholder="__('Choose a material')" :options="$this->materialOptions" />
                    @if ($this->adjustMaterial)
                        @php($current = app(\App\Materials\MaterialStock::class)->levels([$this->adjustMaterial->id])[$this->adjustMaterial->id])
                        <p class="text-xs text-stone-500">{{ __('In stock now') }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ $current['quantity']->format($this->adjustMaterial->unit->decimals) }}</span> {{ $this->adjustMaterial->unit->symbol }}</p>
                    @endif
                </x-ui.field>
                <x-ui.field :label="__('Date')" for="adjust-date" error="adjustForm.adjustment_date" required>
                    <x-ui.input id="adjust-date" type="date" wire:model="adjustForm.adjustment_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                <x-ui.field :label="__('Quantity')" for="adjust-quantity" error="adjustForm.quantity" required>
                    <x-ui.input id="adjust-quantity" wire:model="adjustForm.quantity" inputmode="decimal" dir="ltr" :suffix="$this->adjustMaterial?->unit->symbol" autocomplete="off" />
                </x-ui.field>
                @if (($adjustForm['kind'] ?? '') === 'opening')
                    <x-ui.field :label="__('What it cost (total)')" for="adjust-value" error="adjustForm.value" required :hint="__('Its value enters the books against opening balances.')">
                        <x-ui.input id="adjust-value" wire:model="adjustForm.value" inputmode="decimal" dir="ltr" :suffix="__('Rs')" autocomplete="off" />
                    </x-ui.field>
                @endif

                <x-ui.field :label="__('Reason')" for="adjust-reason" error="adjustForm.reason" :required="($adjustForm['kind'] ?? '') === 'shortage'" class="sm:col-span-2" :hint="($adjustForm['kind'] ?? '') === 'shortage' ? __('For example: counted less, rain damage.') : __('Optional')">
                    <x-ui.input id="adjust-reason" wire:model="adjustForm.reason" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="adjust-form" icon="check" loading="saveAdjust">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
