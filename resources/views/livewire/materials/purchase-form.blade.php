@php use App\Models\MaterialPurchase; use App\Models\MaterialPurchaseCharge; @endphp
@php($paidNow = ($form['payment'] ?? '') === MaterialPurchase::PAID_NOW)
<div>
    <div class="mb-2">
        <a href="{{ route('materials.purchases') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Purchases') }}
        </a>
    </div>

    <x-ui.page-header :title="__('New purchase')" :description="__('Materials bought for the kiln. Transport, excavator and other charges are added to their cost.')" />

    {{-- The party list, sent once with the page (wire:ignore keeps it on later updates). --}}
    <div wire:ignore>
        @unless ($optionsSent)
            <script type="application/json" id="purchase-party-options">@json($this->partyOptions())</script>
        @endunless
    </div>

    <form wire:submit="save" class="space-y-4">
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.field :label="__('Date')" for="purchase-date" error="form.purchase_date" required>
                    <x-ui.input id="purchase-date" type="date" wire:model="form.purchase_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                <fieldset class="lg:col-span-2">
                    <legend class="mb-1.5 text-sm font-medium text-stone-700">{{ __('Payment') }} <span class="text-red-600" aria-hidden="true">*</span></legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ([MaterialPurchase::ON_CREDIT => [__('On credit'), __('Added to the supplier\'s account.')], MaterialPurchase::PAID_NOW => [__('Paid now'), __('Paid from one of our accounts.')]] as $value => [$title, $help])
                            <label @class([
                                'flex cursor-pointer gap-3 rounded-[var(--radius-control)] border p-3 text-sm transition',
                                'border-brand-500 bg-brand-50' => ($form['payment'] ?? '') === $value,
                                'border-stone-300 hover:border-stone-400' => ($form['payment'] ?? '') !== $value,
                            ])>
                                <input type="radio" value="{{ $value }}" wire:model.live="form.payment" class="mt-0.5 size-4 accent-brand-600">
                                <span><span class="block font-medium text-stone-900">{{ $title }}</span><span class="block text-xs text-stone-500">{{ $help }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <x-ui.field :label="__('Supplier')" for="purchase-supplier" error="form.supplier_id" :required="! $paidNow" :hint="$paidNow ? __('Optional when paid now.') : null">
                    <x-ui.combobox id="purchase-supplier" source="purchase-party-options" wire:model.live="form.supplier_id" :placeholder="__('Search a party by name or phone')" />
                    @if ($this->supplierBalance !== null && ! $errors->has('form.supplier_id'))
                        <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                            <span>{{ __('Balance now') }}:</span>
                            <x-party-balance :amount="$this->supplierBalance" class="justify-start!" />
                        </div>
                    @endif
                </x-ui.field>

                @if ($paidNow)
                    <x-ui.field :label="__('Paid from')" for="purchase-account" error="form.account_id" required>
                        <x-ui.select id="purchase-account" wire:model="form.account_id" :placeholder="__('Choose an account')" :options="$this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                    </x-ui.field>
                @endif

                <x-ui.field :label="__('Bill / vehicle number')" for="purchase-reference" error="form.reference" :hint="__('Optional')">
                    <x-ui.input id="purchase-reference" wire:model="form.reference" dir="auto" />
                </x-ui.field>
                <x-ui.field :label="__('Note')" for="purchase-notes" error="form.notes" :hint="__('Optional')" :class="$paidNow ? '' : 'lg:col-span-2'">
                    <x-ui.input id="purchase-notes" wire:model="form.notes" />
                </x-ui.field>
            </div>
        </x-ui.card>

        {{-- Materials --}}
        <x-ui.card>
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-stone-900">{{ __('Materials') }}</h2>
                <a href="{{ route('settings.materials') }}" wire:navigate class="text-xs font-medium text-stone-500 hover:text-stone-800">{{ __('Add materials in Kiln settings.') }}</a>
            </div>

            @error('form.lines')
                <p class="mb-3 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
            @enderror

            <div class="hidden gap-3 border-b border-stone-100 pb-2 text-xs font-medium uppercase tracking-wide text-stone-500 lg:grid lg:grid-cols-[minmax(0,1fr)_9rem_9rem_10rem_2.5rem]">
                <span>{{ __('Material') }}</span>
                <span class="text-end">{{ __('Quantity') }}</span>
                <span class="text-end">{{ __('Rate') }}</span>
                <span class="text-end">{{ __('Amount') }}</span>
                <span class="sr-only">{{ __('Remove') }}</span>
            </div>

            <ol class="divide-y divide-stone-100">
                @foreach ($form['lines'] as $i => $line)
                    @php($material = ctype_digit((string) $line['material_id']) ? $this->materials->get((int) $line['material_id']) : null)
                    <li wire:key="purchase-line-{{ $line['key'] }}" class="grid gap-3 py-3 lg:grid-cols-[minmax(0,1fr)_9rem_9rem_10rem_2.5rem] lg:items-start">
                        <div class="space-y-1">
                            <label for="line-{{ $i }}-material" class="text-xs font-medium text-stone-500 lg:sr-only">{{ __('Material') }}</label>
                            <x-ui.select id="line-{{ $i }}-material" wire:model.live="form.lines.{{ $i }}.material_id" :placeholder="__('Choose a material')"
                                :options="$this->materials->mapWithKeys(fn ($m) => [$m->id => $m->displayName()])->all()" />
                            @error("form.lines.$i.material_id")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="line-{{ $i }}-quantity" class="text-xs font-medium text-stone-500 lg:sr-only">{{ __('Quantity') }}</label>
                            <x-ui.input id="line-{{ $i }}-quantity" wire:model.live.debounce.500ms="form.lines.{{ $i }}.quantity" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="$material?->unit->symbol" autocomplete="off" />
                            @error("form.lines.$i.quantity")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="line-{{ $i }}-rate" class="text-xs font-medium text-stone-500 lg:sr-only">{{ __('Rate') }}</label>
                            <x-ui.input id="line-{{ $i }}-rate" wire:model.live.debounce.500ms="form.lines.{{ $i }}.rate" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" />
                            @error("form.lines.$i.rate")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @else
                                @if ($material)
                                    <p class="text-end text-xs text-stone-500">{{ __('per :unit', ['unit' => $material->unit->symbol ?? $material->unit->displayName()]) }}</p>
                                @endif
                            @enderror
                        </div>
                        <div class="text-end lg:pt-2.5">
                            @if ($this->lineAmounts[$i] ?? null)
                                <x-ui.money :amount="$this->lineAmounts[$i]" class="font-medium text-stone-900" />
                                @if (($this->landedPerUnit[$i] ?? null) && $this->chargesTotal?->isPositive())
                                    <p class="text-xs text-stone-500">{{ __('With charges: :amount per :unit', ['amount' => $this->landedPerUnit[$i]->formatWithCurrency(), 'unit' => $material?->unit->symbol ?? '']) }}</p>
                                @endif
                            @else
                                <span class="text-stone-400">—</span>
                            @endif
                        </div>
                        <div class="flex justify-end lg:pt-1">
                            <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeLine({{ $i }})" :aria-label="__('Remove line :number', ['number' => $i + 1])" :title="__('Remove')" :disabled="count($form['lines']) === 1" />
                        </div>
                    </li>
                @endforeach
            </ol>

            <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addLine" loading="addLine" class="mt-2">{{ __('Add material') }}</x-ui.button>
        </x-ui.card>

        {{-- Extra charges --}}
        <x-ui.card>
            <div class="mb-3">
                <h2 class="text-base font-semibold text-stone-900">{{ __('Extra charges') }}</h2>
                <p class="text-sm text-stone-500">{{ __('Trolley, excavator, loading and other costs of this purchase. They are added to the materials\' cost in proportion to their value.') }}</p>
            </div>

            @error('form.charges')
                <p class="mb-3 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
            @enderror

            @if ($form['charges'] !== [])
                <ol class="mb-2 divide-y divide-stone-100 rounded-[var(--radius-control)] ring-1 ring-stone-200">
                    @foreach ($form['charges'] as $c => $charge)
                        <li wire:key="purchase-charge-{{ $charge['key'] }}" class="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[11rem_minmax(0,1fr)_9rem_minmax(0,1.2fr)_2.5rem] lg:items-start">
                            <div class="space-y-1">
                                <label for="charge-{{ $c }}-kind" class="text-xs font-medium text-stone-500">{{ __('Charge') }}</label>
                                <x-ui.select id="charge-{{ $c }}-kind" wire:model="form.charges.{{ $c }}.kind" :options="\App\Enums\PurchaseChargeKind::options()" />
                            </div>
                            <div class="space-y-1">
                                <label for="charge-{{ $c }}-description" class="text-xs font-medium text-stone-500">{{ __('Details') }}</label>
                                <x-ui.input id="charge-{{ $c }}-description" wire:model="form.charges.{{ $c }}.description" :placeholder="__('Optional')" />
                                @error("form.charges.$c.description")
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="space-y-1">
                                <label for="charge-{{ $c }}-amount" class="text-xs font-medium text-stone-500">{{ __('Amount') }}</label>
                                <x-ui.input id="charge-{{ $c }}-amount" wire:model.live.debounce.500ms="form.charges.{{ $c }}.amount" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" />
                                @error("form.charges.$c.amount")
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="space-y-1">
                                <label for="charge-{{ $c }}-payee" class="text-xs font-medium text-stone-500">{{ __('Paid how') }}</label>
                                <x-ui.select id="charge-{{ $c }}-payee" wire:model.live="form.charges.{{ $c }}.payee" :options="[
                                    MaterialPurchaseCharge::WITH_GOODS => $paidNow ? __('Paid with the goods') : __('On the supplier\'s bill'),
                                    MaterialPurchaseCharge::TO_PARTY => __('Owed to another party'),
                                    MaterialPurchaseCharge::FROM_ACCOUNT => __('Paid from an account'),
                                ]" />
                                @if ($charge['payee'] === MaterialPurchaseCharge::TO_PARTY)
                                    <label for="charge-{{ $c }}-party" class="sr-only">{{ __('Party') }}</label>
                                    <x-ui.combobox id="charge-{{ $c }}-party" source="purchase-party-options" wire:model="form.charges.{{ $c }}.party_id" :placeholder="__('Who is owed, e.g. the trolley driver')" />
                                    @error("form.charges.$c.party_id")
                                        <p class="text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                @elseif ($charge['payee'] === MaterialPurchaseCharge::FROM_ACCOUNT)
                                    <label for="charge-{{ $c }}-account" class="sr-only">{{ __('Account') }}</label>
                                    <x-ui.select id="charge-{{ $c }}-account" wire:model="form.charges.{{ $c }}.account_id" :placeholder="__('Choose an account')" :options="$this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                                    @error("form.charges.$c.account_id")
                                        <p class="text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                @endif
                            </div>
                            <div class="flex justify-end lg:pt-6">
                                <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeCharge({{ $c }})" :aria-label="__('Remove charge :number', ['number' => $c + 1])" :title="__('Remove')" />
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif

            <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addCharge" loading="addCharge">{{ __('Add charge') }}</x-ui.button>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <dl class="grid grid-cols-3 gap-4 sm:gap-8">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Goods') }}</dt>
                    <dd class="mt-1 font-semibold text-stone-900"><x-ui.money :amount="$this->goodsTotal" /></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Charges') }}</dt>
                    <dd class="mt-1 font-semibold text-stone-900">
                        @if ($this->chargesTotal !== null)
                            <x-ui.money :amount="$this->chargesTotal" />
                        @else
                            <span class="text-sm text-red-600">{{ __('Check the amounts') }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total cost') }}</dt>
                    <dd class="mt-1 text-xl font-semibold text-stone-900"><x-ui.money :amount="$this->totalCost" /></dd>
                </div>
            </dl>
            <div class="flex shrink-0 items-center gap-2">
                <x-ui.button variant="secondary" :href="route('materials.purchases')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" loading="save">{{ __('Save purchase') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
