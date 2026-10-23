@php use App\Enums\ProductionEntryKind; use App\Enums\StageKind; use App\Models\Unit; @endphp
@php($stageName = fn (StageKind $kind) => $this->stages->get($kind->value)?->displayName() ?? $kind->defaultName())
<div>
    <x-ui.modal wire:model="showForm" :title="$type?->label()" max-width="xl">
        @if ($type)
            <form id="production-form" wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Date')" for="production-date" error="form.entry_date" required>
                        <x-ui.input id="production-date" type="date" wire:model="form.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                    </x-ui.field>

                    @if ($type === ProductionEntryKind::Pathai)
                        <x-ui.field :label="__('Team or worker')" for="production-team" error="form.team" :hint="__('Optional. Workers are linked to entries with labour (M7).')">
                            <x-ui.input id="production-team" wire:model="form.team" />
                        </x-ui.field>
                        <x-ui.field :label="__('Bricks made')" for="production-made" error="form.made" required>
                            <x-ui.input id="production-made" wire:model.live.debounce.400ms="form.made" inputmode="numeric" dir="ltr" class="tabular" autocomplete="off" />
                        </x-ui.field>
                        <x-ui.field :label="__('Rejected')" for="production-rejected" error="form.rejected" :hint="__('Poor bricks; they are not counted or paid.')">
                            <x-ui.input id="production-rejected" wire:model.live.debounce.400ms="form.rejected" inputmode="numeric" dir="ltr" class="tabular" autocomplete="off" />
                        </x-ui.field>
                        @php($accepted = \App\Actions\Production\RecordPathai::preview((string) $form['made'], (string) $form['rejected']))
                        <div class="rounded-[var(--radius-control)] bg-stone-50 px-3 py-2 text-sm ring-1 ring-stone-200 sm:col-span-2">
                            <span class="text-stone-600">{{ __('Accepted, into ":stage"', ['stage' => $stageName(StageKind::Raw)]) }}:</span>
                            <span class="tabular font-semibold text-stone-900" dir="ltr">{{ $accepted?->format(0) ?? '—' }}</span>
                        </div>
                    @elseif ($type === ProductionEntryKind::Loading)
                        <x-ui.field :label="__('Bricks loaded into the kiln')" for="production-quantity" error="form.quantity" required>
                            <x-ui.input id="production-quantity" wire:model="form.quantity" inputmode="numeric" dir="ltr" class="tabular" autocomplete="off" />
                            <p class="text-xs text-stone-500">{{ __('Available in ":stage"', ['stage' => $stageName(StageKind::Raw)]) }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ $this->levels['raw']->format(0) }}</span></p>
                        </x-ui.field>
                    @elseif ($type === ProductionEntryKind::Firing)
                        <x-ui.field :label="__('Bricks fired, now awaiting Nakaasi')" for="production-quantity" error="form.quantity" :hint="__('Leave empty if you only record fuel today.')">
                            <x-ui.input id="production-quantity" wire:model="form.quantity" inputmode="numeric" dir="ltr" class="tabular" autocomplete="off" />
                            <p class="text-xs text-stone-500">{{ __('Available in ":stage"', ['stage' => $stageName(StageKind::InFiring)]) }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ $this->levels['in_firing']->format(0) }}</span></p>
                        </x-ui.field>
                    @elseif ($type === ProductionEntryKind::Adjustment)
                        <x-ui.field :label="__('Stage')" for="production-stage" error="form.stage" required>
                            <x-ui.select id="production-stage" wire:model.live="form.stage" :options="$this->stages->mapWithKeys(fn ($s) => [$s->kind->value => $s->displayName()])->all()" />
                        </x-ui.field>
                        @if (($form['stage'] ?? '') === StageKind::Saleable->value)
                            <x-ui.field :label="__('Grade')" for="production-product" error="form.product_id" required>
                                <x-ui.select id="production-product" wire:model.live="form.product_id" :placeholder="__('Choose the grade')" :options="$this->gradeProducts->mapWithKeys(fn ($p) => [$p->id => $p->displayName()])->all()" />
                            </x-ui.field>
                        @endif
                        <fieldset class="sm:col-span-2">
                            <legend class="mb-1.5 text-sm font-medium text-stone-700">{{ __('Correction') }}</legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (['remove' => __('Fewer bricks (broken, lost, counted less)'), 'add' => __('More bricks (counted more)')] as $value => $label)
                                    <label @class([
                                        'flex cursor-pointer items-center gap-2 rounded-[var(--radius-control)] border px-3 py-2 text-sm transition',
                                        'border-brand-500 bg-brand-50 text-brand-900' => ($form['direction'] ?? '') === $value,
                                        'border-stone-300 text-stone-700 hover:border-stone-400' => ($form['direction'] ?? '') !== $value,
                                    ])>
                                        <input type="radio" value="{{ $value }}" wire:model.live="form.direction" class="size-4 accent-brand-600"> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <x-ui.field :label="__('Quantity')" for="production-quantity" error="form.quantity" required>
                            <x-ui.input id="production-quantity" wire:model="form.quantity" inputmode="decimal" dir="ltr" class="tabular" autocomplete="off" />
                            @php($slotStage = StageKind::tryFrom((string) ($form['stage'] ?? '')))
                            @if ($slotStage && $slotStage !== StageKind::Saleable)
                                <p class="text-xs text-stone-500">{{ __('In stock now') }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ $this->levels[$slotStage->value]->format(0) }}</span></p>
                            @elseif ($slotStage && ctype_digit((string) $form['product_id']))
                                <p class="text-xs text-stone-500">{{ __('In stock now') }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ ($this->levels['saleable'][(int) $form['product_id']] ?? \App\Support\Quantity::zero())->format($this->gradeProducts->firstWhere('id', (int) $form['product_id'])?->unit->decimals ?? 0) }}</span></p>
                            @endif
                        </x-ui.field>
                        <x-ui.field :label="__('Reason')" for="production-reason" error="form.reason" required class="sm:col-span-2">
                            <x-ui.input id="production-reason" wire:model="form.reason" :placeholder="__('For example: broken in rain, counted more.')" />
                        </x-ui.field>
                    @endif
                </div>

                {{-- Fuel burnt (firing) --}}
                @if ($type === ProductionEntryKind::Firing)
                    <div>
                        <p class="mb-2 text-sm font-medium text-stone-700">{{ __('Fuel used') }}</p>
                        @error('form.fuel')
                            <p class="mb-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @if (($form['fuel'] ?? []) !== [])
                            <ol class="mb-2 divide-y divide-stone-100 rounded-[var(--radius-control)] ring-1 ring-stone-200">
                                @foreach ($form['fuel'] as $f => $fuel)
                                    @php($material = ctype_digit((string) $fuel['material_id']) ? $this->fuelMaterials->get((int) $fuel['material_id']) : null)
                                    <li wire:key="fuel-{{ $fuel['key'] }}" class="grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_10rem_2.5rem] sm:items-start">
                                        <div>
                                            <label for="fuel-{{ $f }}-material" class="sr-only">{{ __('Material') }}</label>
                                            <x-ui.select id="fuel-{{ $f }}-material" wire:model.live="form.fuel.{{ $f }}.material_id" :placeholder="__('Choose a material')" :options="$this->fuelMaterials->mapWithKeys(fn ($m) => [$m->id => $m->displayName()])->all()" />
                                            @error("form.fuel.$f.material_id")
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="fuel-{{ $f }}-quantity" class="sr-only">{{ __('Quantity') }}</label>
                                            <x-ui.input id="fuel-{{ $f }}-quantity" wire:model="form.fuel.{{ $f }}.quantity" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="$material?->unit->symbol" autocomplete="off" />
                                            @error("form.fuel.$f.quantity")
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div class="flex justify-end">
                                            <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeFuel({{ $f }})" :aria-label="__('Remove line :number', ['number' => $f + 1])" :title="__('Remove')" />
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addFuel" loading="addFuel">{{ __('Add fuel') }}</x-ui.button>
                        <p class="mt-1 text-xs text-stone-500">{{ __('Fuel is taken out of material stock at its average cost.') }}</p>
                    </div>
                @endif

                {{-- Grades out of Nakaasi --}}
                @if ($type === ProductionEntryKind::Nakaasi)
                    @php($total = $this->nakaasiTotal())
                    <div>
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-medium text-stone-700">{{ __('Bricks by grade') }}</p>
                            <p class="text-xs text-stone-500">{{ __('Available in ":stage"', ['stage' => $stageName(StageKind::FiredAwaitingUnloading)]) }}: <span class="tabular font-medium text-stone-700" dir="ltr">{{ $this->levels['fired_awaiting_unloading']->format(0) }}</span></p>
                        </div>
                        @error('form.lines')
                            <p class="mb-2 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                        @enderror
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($form['lines'] ?? [] as $l => $line)
                                @php($product = $this->gradeProducts->firstWhere('id', (int) $line['product_id']))
                                @if ($product)
                                    <x-ui.field :label="$product->displayName()" for="grade-{{ $l }}" error="form.lines.{{ $l }}.quantity"
                                        :hint="$product->unit->code !== Unit::BRICK ? __('Counted by the :unit, not as bricks.', ['unit' => mb_strtolower($product->unit->displayName())]) : null">
                                        <x-ui.input id="grade-{{ $l }}" wire:model.live.debounce.400ms="form.lines.{{ $l }}.quantity" inputmode="decimal" dir="ltr" class="tabular" :suffix="$product->unit->code !== Unit::BRICK ? $product->unit->symbol : null" autocomplete="off" />
                                    </x-ui.field>
                                @endif
                            @endforeach
                        </div>
                        <div class="mt-3 rounded-[var(--radius-control)] bg-stone-50 px-3 py-2 text-sm ring-1 ring-stone-200">
                            <span class="text-stone-600">{{ __('Bricks unloaded') }}:</span>
                            <span class="tabular font-semibold text-stone-900" dir="ltr">{{ $total?->format(0) ?? '—' }}</span>
                            @if ($total && $total->greaterThan($this->levels['fired_awaiting_unloading']))
                                <span class="ms-2 text-red-600">{{ __('More than are awaiting Nakaasi.') }}</span>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($type !== ProductionEntryKind::Adjustment)
                    <x-ui.field :label="__('Note')" for="production-notes" error="form.notes" :hint="__('Optional')">
                        <x-ui.input id="production-notes" wire:model="form.notes" />
                    </x-ui.field>
                @endif
            </form>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="production-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
