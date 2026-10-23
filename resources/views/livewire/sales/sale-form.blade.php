@php($deliverNow = (bool) ($form['deliver_now'] ?? false))
<div>
    <div class="mb-2">
        <a href="{{ route('sales.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Sales') }}
        </a>
    </div>

    <x-ui.page-header :title="__('New sale')" :description="__('The customer owes the total once the sale is saved. Bricks stay reserved for them until they are collected.')" />

    {{-- The customer list, sent once with the page (wire:ignore keeps it on later updates). --}}
    <div wire:ignore>
        @unless ($optionsSent)
            <script type="application/json" id="sale-customer-options">@json($this->customerOptions())</script>
        @endunless
    </div>

    <form wire:submit="save" class="space-y-4">
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.field :label="__('Customer')" for="sale-customer" error="form.customer_id" required>
                    <x-ui.combobox id="sale-customer" source="sale-customer-options" wire:model.live="form.customer_id" :placeholder="__('Search a customer by name or phone')" />
                    @if ($this->customerBalance !== null && ! $errors->has('form.customer_id'))
                        <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                            <span>{{ __('Balance now') }}:</span>
                            <x-party-balance :amount="$this->customerBalance" class="justify-start!" />
                        </div>
                    @endif
                </x-ui.field>

                <x-ui.field :label="__('Date')" for="sale-date" error="form.sale_date" required>
                    <x-ui.input id="sale-date" type="date" wire:model="form.sale_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                <x-ui.field :label="__('Sales agent')" for="sale-agent" error="form.agent_id" :hint="__('Optional')">
                    <x-ui.select id="sale-agent" wire:model="form.agent_id" :placeholder="__('No agent')" :options="$this->agents->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                </x-ui.field>

                <x-ui.field :label="__('Note')" for="sale-notes" error="form.notes" :hint="__('Optional')" class="sm:col-span-2 lg:col-span-3">
                    <x-ui.input id="sale-notes" wire:model="form.notes" />
                </x-ui.field>
            </div>
        </x-ui.card>

        {{-- Grades --}}
        <x-ui.card>
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-stone-900">{{ __('Bricks') }}</h2>
                <a href="{{ route('production.index') }}" wire:navigate class="text-xs font-medium text-stone-500 hover:text-stone-800">{{ __('See brick stock') }}</a>
            </div>

            @error('form.lines')
                <p class="mb-3 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
            @enderror

            <div class="hidden gap-3 border-b border-stone-100 pb-2 text-xs font-medium uppercase tracking-wide text-stone-500 xl:grid xl:grid-cols-[minmax(0,1fr)_8rem_8rem_7rem_8rem_2.5rem]">
                <span>{{ __('Grade') }}</span>
                <span class="text-end">{{ __('Quantity') }}</span>
                <span class="text-end">{{ __('Rate') }}</span>
                <span class="text-end">{{ __('Discount') }}</span>
                <span class="text-end">{{ __('Amount') }}</span>
                <span class="sr-only">{{ __('Remove') }}</span>
            </div>

            <ol class="divide-y divide-stone-100">
                @foreach ($form['lines'] as $i => $line)
                    @php($product = $this->productOf($line))
                    @php($stock = $product ? ($this->availability[$product->id] ?? null) : null)
                    <li wire:key="sale-line-{{ $line['key'] }}" class="grid gap-3 py-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_8rem_8rem_7rem_8rem_2.5rem] xl:items-start">
                        <div class="space-y-1 sm:col-span-2 xl:col-span-1">
                            <label for="line-{{ $i }}-product" class="text-xs font-medium text-stone-500 xl:sr-only">{{ __('Grade') }}</label>
                            <x-ui.select id="line-{{ $i }}-product" wire:model.live="form.lines.{{ $i }}.product_id" :placeholder="__('Choose a grade')"
                                :options="$this->products->mapWithKeys(fn ($p) => [$p->id => $p->displayName()])->all()" />
                            @error("form.lines.$i.product_id")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @else
                                @if ($stock)
                                    <p class="text-xs text-stone-500">
                                        {{ __('Free to sell: :quantity', ['quantity' => ($stock['free']->isNegative() ? '0' : $stock['free']->format($product->unit->decimals))]) }}
                                        @if ($stock['reserved']->isPositive())
                                            <span class="text-stone-400">· {{ __(':quantity waiting for other sales', ['quantity' => $stock['reserved']->format($product->unit->decimals)]) }}</span>
                                        @endif
                                    </p>
                                @endif
                            @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="line-{{ $i }}-quantity" class="text-xs font-medium text-stone-500 xl:sr-only">{{ __('Quantity') }}</label>
                            <x-ui.input id="line-{{ $i }}-quantity" wire:model.live.debounce.500ms="form.lines.{{ $i }}.quantity" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="$product?->unit->symbol" autocomplete="off" />
                            @error("form.lines.$i.quantity")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="line-{{ $i }}-rate" class="text-xs font-medium text-stone-500 xl:sr-only">{{ __('Rate') }}</label>
                            <x-ui.input id="line-{{ $i }}-rate" wire:model.live.debounce.500ms="form.lines.{{ $i }}.rate" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" />
                            @error("form.lines.$i.rate")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @else
                                @if ($product)
                                    <p class="text-end text-xs text-stone-500">{{ App\Actions\Sales\RecordSale::ratePer($product) === 1000 ? __('per 1,000 bricks') : __('per :unit', ['unit' => $product->unit->symbol ?? $product->unit->displayName()]) }}</p>
                                @endif
                            @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="line-{{ $i }}-discount" class="text-xs font-medium text-stone-500 xl:sr-only">{{ __('Discount') }}</label>
                            <x-ui.input id="line-{{ $i }}-discount" wire:model.live.debounce.500ms="form.lines.{{ $i }}.discount" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" :placeholder="__('None')" />
                            @error("form.lines.$i.discount")
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="text-end sm:self-center xl:self-auto xl:pt-2.5">
                            @if ($this->lineAmounts[$i] ?? null)
                                <x-ui.money :amount="$this->lineAmounts[$i]" class="font-medium text-stone-900" />
                            @else
                                <span class="text-stone-400">—</span>
                            @endif
                        </div>
                        <div class="flex justify-end sm:self-center xl:self-auto xl:pt-1">
                            <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeLine({{ $i }})" :aria-label="__('Remove line :number', ['number' => $i + 1])" :title="__('Remove')" :disabled="count($form['lines']) === 1" />
                        </div>
                    </li>
                @endforeach
            </ol>

            <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addLine" loading="addLine" class="mt-2">{{ __('Add grade') }}</x-ui.button>
        </x-ui.card>

        {{-- Taken now / paid now --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card>
                <x-ui.checkbox id="sale-deliver-now" wire:model.live="form.deliver_now" :label="__('Bricks taken now')" :description="__('Everything on this sale leaves with the customer now (one delivery). Otherwise record deliveries later, over one or more trips.')" />

                @if ($deliverNow)
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-ui.field :label="__('Driver')" for="sale-driver" error="form.driver_id" :hint="__('Only when the kiln arranged the transport.')">
                            <x-ui.select id="sale-driver" wire:model="form.driver_id" :placeholder="__('Customer\'s own transport')" :options="$this->drivers->mapWithKeys(fn ($d) => [$d->id => $d->displayName()])->all()" />
                        </x-ui.field>
                        <x-ui.field :label="__('Vehicle')" for="sale-vehicle" error="form.vehicle" :hint="__('Optional')">
                            <x-ui.input id="sale-vehicle" wire:model="form.vehicle" dir="auto" :placeholder="__('Trolley or truck number')" />
                        </x-ui.field>
                        <x-ui.field :label="__('Carriage')" for="sale-carriage" error="form.carriage" :hint="__('Charged to the customer and owed to the driver.')" class="sm:col-span-2">
                            <x-ui.input id="sale-carriage" wire:model.live.debounce.500ms="form.carriage" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" :placeholder="__('None')" />
                        </x-ui.field>
                    </div>
                @endif
            </x-ui.card>

            @if ($canReceive)
                <x-ui.card>
                    <h2 class="text-sm font-medium text-stone-800">{{ __('Money received now') }}</h2>
                    <p class="text-xs text-stone-500">{{ __('Optional. Recorded as a receipt from the customer; anything more than the sale stays on their account as an advance.') }}</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-ui.field :label="__('Amount')" for="sale-received" error="form.received">
                            <x-ui.input id="sale-received" wire:model.live.debounce.500ms="form.received" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" :placeholder="__('None')" />
                        </x-ui.field>
                        <x-ui.field :label="__('Received into')" for="sale-account" error="form.account_id">
                            <x-ui.select id="sale-account" wire:model="form.account_id" :placeholder="__('Choose an account')" :options="$this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                        </x-ui.field>
                    </div>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 sm:gap-8">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Bricks') }}</dt>
                    <dd class="mt-1 font-semibold text-stone-900"><x-ui.money :amount="$this->goodsTotal" /></dd>
                </div>
                @if ($deliverNow)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Carriage') }}</dt>
                        <dd class="mt-1 font-semibold text-stone-900">
                            @if ($this->carriage !== null)
                                <x-ui.money :amount="$this->carriage" />
                            @else
                                <span class="text-sm text-red-600">{{ __('Check the amount') }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Customer owes') }}</dt>
                    <dd class="mt-1 text-xl font-semibold text-stone-900"><x-ui.money :amount="$this->goodsTotal->add($this->carriage ?? App\Support\Money::zero())" /></dd>
                </div>
            </dl>
            <div class="flex shrink-0 items-center gap-2">
                <x-ui.button variant="secondary" :href="route('sales.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" loading="save">{{ __('Save sale') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
