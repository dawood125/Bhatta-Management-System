<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Materials used')" :description="__('Soil, fuel and other materials taken from stock, with what they cost.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
                @if ($canRecord)
                    <x-ui.button icon="flame" wire:click="create">{{ __('Record usage') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
        <x-materials.tabs active="usage" />
    </div>

    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Materials used') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="usage-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-2xl">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Cost of materials used') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->totalCost" /></p>
                <p class="text-xs text-stone-500">{{ __('Reversed entries are not counted.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:w-56 print:hidden">
                    <label for="usage-material" class="sr-only">{{ __('Material') }}</label>
                    <x-ui.select id="usage-material" wire:model.live="material" :placeholder="__('All materials')" :options="$this->materialOptions" />
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-64">{{ __('Materials') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Cost') }}</x-ui.th>
                @if ($canReverse)
                    <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                @endif
            </x-slot:head>

            @forelse ($this->usages as $usage)
                <tr wire:key="usage-{{ $usage->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $usage->isReversed()]) wire:loading.class="opacity-60" wire:target="material,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $usage->usage_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular font-medium text-stone-800" dir="ltr">{{ $usage->entry->number }}</span></x-ui.td>
                    <x-ui.td>
                        @if ($usage->isReversed())
                            <x-ui.badge tone="danger" icon="undo-2" class="mb-1">{{ __('Reversed') }}</x-ui.badge>
                        @endif
                        <ul class="space-y-0.5">
                            @foreach ($usage->movements as $movement)
                                <li class="flex flex-wrap justify-between gap-x-4">
                                    <span>
                                        <span class="text-stone-900">{{ $movement->material->displayName() }}</span>
                                        <span class="tabular text-stone-600" dir="ltr">{{ $movement->quantity_out->format($movement->material->unit->decimals) }}</span>
                                        <span class="text-xs text-stone-500">{{ $movement->material->unit->symbol }}</span>
                                        @if (filled($movement->memo))
                                            <span class="block text-xs text-stone-500"><bdi>{{ $movement->memo }}</bdi></span>
                                        @endif
                                    </span>
                                    <x-ui.money :amount="$movement->value_out" :currency="false" class="text-xs text-stone-500" />
                                </li>
                            @endforeach
                        </ul>
                        @if (filled($usage->notes))
                            <p class="mt-1 text-xs text-stone-500"><bdi>{{ $usage->notes }}</bdi></p>
                        @endif
                        <p class="mt-0.5 text-xs text-stone-400 print:hidden">{{ __('By :name', ['name' => $usage->creator?->name ?? __('System')]) }}</p>
                    </x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $usage->isReversed()])><x-ui.money :amount="$usage->total_cost" /></x-ui.td>
                    @if ($canReverse)
                        <x-ui.td align="end" class="print:hidden">
                            @unless ($usage->isReversed())
                                <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $usage->journal_entry_id }})" :aria-label="__('Reverse :number', ['number' => $usage->entry->number])" :title="__('Reverse')" />
                            @endunless
                        </x-ui.td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canReverse ? 5 : 4 }}">
                        <x-ui.empty-state icon="flame" :title="__('Nothing used in this period')" :description="__('Record the coal, Bora and other materials taken from stock.')">
                            @if ($canRecord && $material === '')
                                <x-ui.button icon="flame" variant="secondary" wire:click="create">{{ __('Record usage') }}</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse

            @if ($this->usages->hasPages())
                <x-slot:footer>{{ $this->usages->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif

    @if ($canRecord)
        <x-ui.modal wire:model="showForm" :title="__('Record usage')" :description="__('Each material is taken out of stock at its average cost.')" max-width="xl">
            <form id="usage-form" wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Date')" for="usage-date" error="form.usage_date" required>
                        <x-ui.input id="usage-date" type="date" wire:model="form.usage_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Note')" for="usage-notes" error="form.notes" :hint="__('Optional')">
                        <x-ui.input id="usage-notes" wire:model="form.notes" />
                    </x-ui.field>
                </div>

                @error('form.lines')
                    <p class="flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                @enderror

                <ol class="divide-y divide-stone-100 rounded-[var(--radius-control)] ring-1 ring-stone-200">
                    @foreach ($form['lines'] ?? [] as $i => $line)
                        @php($chosen = ctype_digit((string) $line['material_id']) ? $this->activeMaterials->get((int) $line['material_id']) : null)
                        <li wire:key="usage-line-{{ $line['key'] }}" class="grid gap-3 p-3 sm:grid-cols-[minmax(0,1fr)_9rem_minmax(0,0.8fr)_2.5rem] sm:items-start">
                            <div class="space-y-1">
                                <label for="usage-line-{{ $i }}-material" class="text-xs font-medium text-stone-500">{{ __('Material') }}</label>
                                <x-ui.select id="usage-line-{{ $i }}-material" wire:model.live="form.lines.{{ $i }}.material_id" :placeholder="__('Choose a material')"
                                    :options="$this->activeMaterials->mapWithKeys(fn ($m) => [$m->id => $m->displayName()])->all()" />
                                @error("form.lines.$i.material_id")
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @else
                                    @if ($chosen)
                                        <p class="text-xs text-stone-500">{{ __('In stock') }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ $this->levels[$chosen->id]['quantity']->format($chosen->unit->decimals) }}</span> {{ $chosen->unit->symbol }}</p>
                                    @endif
                                @enderror
                            </div>
                            <div class="space-y-1">
                                <label for="usage-line-{{ $i }}-quantity" class="text-xs font-medium text-stone-500">{{ __('Quantity') }}</label>
                                <x-ui.input id="usage-line-{{ $i }}-quantity" wire:model.live.debounce.500ms="form.lines.{{ $i }}.quantity" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="$chosen?->unit->symbol" autocomplete="off" />
                                @error("form.lines.$i.quantity")
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @else
                                    @if ($cost = $this->lineCost($i))
                                        <p class="text-xs text-stone-500">{{ __('Cost') }}: <x-ui.money :amount="$cost" class="font-medium text-stone-700" /></p>
                                    @endif
                                @enderror
                            </div>
                            <div class="space-y-1">
                                <label for="usage-line-{{ $i }}-memo" class="text-xs font-medium text-stone-500">{{ __('Remarks') }}</label>
                                <x-ui.input id="usage-line-{{ $i }}-memo" wire:model="form.lines.{{ $i }}.memo" :placeholder="__('Optional')" />
                            </div>
                            <div class="flex justify-end sm:pt-6">
                                <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeLine({{ $i }})" :aria-label="__('Remove line :number', ['number' => $i + 1])" :title="__('Remove')" :disabled="count($form['lines']) === 1" />
                            </div>
                        </li>
                    @endforeach
                </ol>

                <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addLine" loading="addLine">{{ __('Add material') }}</x-ui.button>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="usage-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-materials.reverse-modal :number="$this->reversingNumber()" />
</div>
