@php use App\Support\Quantity; @endphp
<div>
    <div class="mb-2">
        <a href="{{ route('sales.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Sales') }}
        </a>
    </div>

    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
            <h1 class="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight text-stone-900">
                {{ __('Sale') }} <span class="tabular" dir="ltr">{{ $sale->number }}</span>
                <x-ui.badge :tone="$sale->status->tone()">{{ $sale->status->label() }}</x-ui.badge>
                @if ($payment['status'])
                    <x-ui.badge :tone="$payment['status']->tone()">{{ $payment['status']->label() }}</x-ui.badge>
                @endif
            </h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-stone-500">
                <span><span class="tabular" dir="ltr">{{ $sale->sale_date->format('d-m-Y') }}</span></span>
                <span>{{ __('Customer') }}: <a href="{{ route('parties.show', $sale->customer) }}" wire:navigate class="font-medium text-stone-800 hover:underline">{{ $sale->customer->displayName() }}</a></span>
                @if ($sale->agent)
                    <span>{{ __('Agent: :name', ['name' => $sale->agent->displayName()]) }}</span>
                @endif
                <span>{{ __('By :name', ['name' => $sale->creator?->name ?? __('System')]) }}</span>
            </div>
            @if (filled($sale->notes))
                <p class="mt-2 text-stone-700"><bdi>{{ $sale->notes }}</bdi></p>
            @endif
            {{-- The date is set left to right so it reads correctly inside an Urdu sentence; the name is escaped. --}}
            @if ($sale->isCancelled())
                <div class="mt-2 text-sm text-red-700">
                    <p>{!! __('Cancelled by :name on :date', ['name' => e($sale->canceller?->name ?? __('System')), 'date' => '<span class="tabular" dir="ltr">'.e($sale->cancelled_at?->format('d-m-Y')).'</span>']) !!}</p>
                    <p><bdi>{{ $sale->cancel_reason }}</bdi></p>
                </div>
            @endif
            @if ($sale->isClosed())
                <div class="mt-2 text-sm text-amber-800">
                    <p>{!! __('Closed by :name on :date', ['name' => e($sale->closer?->name ?? __('System')), 'date' => '<span class="tabular" dir="ltr">'.e($sale->closed_at?->format('d-m-Y')).'</span>']) !!}</p>
                    <p><bdi>{{ $sale->close_reason }}</bdi></p>
                </div>
            @endif
        </div>

        <div class="flex shrink-0 flex-col items-start gap-2 xl:items-end">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Still to pay') }}</p>
            <x-ui.money :amount="$payment['outstanding']" class="text-2xl font-semibold text-stone-900" />
            <div class="flex flex-wrap gap-2 xl:justify-end">
                <x-ui.button size="sm" variant="secondary" icon="file-text" :href="route('sales.invoice', $sale)" target="_blank">{{ __('Invoice PDF') }}</x-ui.button>
                @if ($canEmail)
                    <x-ui.button size="sm" variant="secondary" icon="mail" wire:click="emailInvoice" loading="emailInvoice">{{ __('Email invoice') }}</x-ui.button>
                @endif
                @if ($canReceive)
                    <x-ui.button size="sm" variant="secondary" icon="hand-coins" wire:click="openPayment">{{ __('Receive payment') }}</x-ui.button>
                @endif
                @if ($canDeliver)
                    <x-ui.button size="sm" icon="truck" wire:click="openDelivery">{{ __('Record delivery') }}</x-ui.button>
                @endif
            </div>
            @error('email')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-4">
            {{-- Lines --}}
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th class="min-w-36">{{ __('Grade') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Ordered') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Delivered') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('To deliver') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Rate') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
                </x-slot:head>

                @foreach ($sale->lines as $line)
                    @php($decimals = $line->product->unit->decimals)
                    @php($delivered = $this->delivered[$line->id] ?? 0)
                    @php($remaining = $sale->status === App\Enums\SaleStatus::Open ? $line->quantity->milli - $delivered : 0)
                    <tr wire:key="line-{{ $line->id }}">
                        <x-ui.td class="font-medium text-stone-900">{{ $line->product->displayName() }}</x-ui.td>
                        <x-ui.td align="end" class="tabular">{{ $line->quantity->format($decimals) }} <span class="text-xs text-stone-500">{{ $line->product->unit->symbol }}</span></x-ui.td>
                        <x-ui.td align="end" class="tabular">{{ Quantity::fromMilli($delivered)->format($decimals) }}</x-ui.td>
                        <x-ui.td align="end" @class(['tabular', 'font-semibold text-amber-700' => $remaining > 0, 'text-stone-400' => $remaining <= 0])>{{ Quantity::fromMilli(max(0, $remaining))->format($decimals) }}</x-ui.td>
                        <x-ui.td align="end">
                            <x-ui.money :amount="$line->rate" :currency="false" />
                            <p class="text-xs text-stone-500">{{ $line->rate_per === 1000 ? __('per 1,000 bricks') : __('per :unit', ['unit' => $line->product->unit->symbol ?? $line->product->unit->displayName()]) }}</p>
                        </x-ui.td>
                        <x-ui.td align="end">
                            <x-ui.money :amount="$line->amount" :currency="false" class="font-medium" />
                            @if ($line->discount->isPositive())
                                <p class="text-xs text-stone-500">{{ __('After :amount discount', ['amount' => $line->discount->format()]) }}</p>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>

            {{-- Deliveries --}}
            <x-ui.card :padding="false">
                <div class="flex items-center justify-between gap-3 border-b border-stone-100 px-4 py-3">
                    <h2 class="text-base font-semibold text-stone-900">{{ __('Deliveries') }}</h2>
                </div>
                @forelse ($sale->dispatches as $dispatch)
                    <div wire:key="dispatch-{{ $dispatch->id }}" @class(['flex flex-col gap-2 border-b border-stone-100 px-4 py-3 last:border-0 sm:flex-row sm:items-start sm:justify-between', 'bg-red-50/40' => $dispatch->isReversed()])>
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-stone-900">
                                <span class="tabular" dir="ltr">{{ $dispatch->number }}</span>
                                <span class="tabular text-sm font-normal text-stone-500" dir="ltr">{{ $dispatch->dispatch_date->format('d-m-Y') }}</span>
                                @if ($dispatch->isReversed())
                                    <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                                @endif
                            </p>
                            <p class="text-sm text-stone-600">
                                @foreach ($dispatch->lines as $dl)
                                    @php($line = $sale->lines->firstWhere('id', $dl->sale_line_id))
                                    <span class="whitespace-nowrap">{{ $line?->product->displayName() }} <bdi class="tabular">{{ $dl->quantity->format($line?->product->unit->decimals ?? 0) }}</bdi></span>@if (! $loop->last)<span class="text-stone-300"> · </span>@endif
                                @endforeach
                            </p>
                            @if ($dispatch->vehicle || $dispatch->driver)
                                <p class="text-xs text-stone-500">
                                    <x-lucide-truck class="inline size-3.5" aria-hidden="true" />
                                    {{ collect([$dispatch->vehicle, $dispatch->driver?->displayName()])->filter()->implode(' · ') }}
                                    @if ($dispatch->carriage->isPositive())
                                        · {{ __('Carriage :amount', ['amount' => $dispatch->carriage->formatWithCurrency()]) }}
                                    @endif
                                </p>
                            @endif
                            @if ($dispatch->isReversed())
                                <p class="text-xs text-red-700">{{ __('Reversed by :name', ['name' => $dispatch->reverser?->name ?? __('System')]) }}: <bdi>{{ $dispatch->reversal_reason }}</bdi></p>
                            @endif
                        </div>
                        @if ($canAdjust && ! $dispatch->isReversed() && ! $sale->isClosed())
                            <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $dispatch->id }})">{{ __('Reverse') }}</x-ui.button>
                        @endif
                    </div>
                @empty
                    <x-ui.empty-state icon="truck" :title="__('Nothing delivered yet')" :description="__('Record each trip when the bricks leave the kiln.')" />
                @endforelse
            </x-ui.card>

            @if ($emails->isNotEmpty())
                <x-ui.card :padding="false">
                    <div class="border-b border-stone-100 px-4 py-3">
                        <h2 class="text-base font-semibold text-stone-900">{{ __('Invoice emails') }}</h2>
                    </div>
                    @foreach ($emails as $email)
                        <div wire:key="email-{{ $email->id }}" class="flex flex-col gap-1 border-b border-stone-100 px-4 py-3 text-sm last:border-0 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-stone-800" dir="ltr">{{ $email->recipients }}</p>
                                <p class="text-xs text-stone-500"><span class="tabular" dir="ltr">{{ $email->created_at->format('d-m-Y H:i') }}</span> · {{ trans_choice(':count attempt|:count attempts', $email->attempts, ['count' => $email->attempts]) }}</p>
                                @if ($email->last_error && $email->status !== App\Models\EmailLog::STATUS_SENT)
                                    <p class="text-xs text-red-700" dir="ltr">{{ Str::limit($email->last_error, 160) }}</p>
                                @endif
                            </div>
                            <x-ui.badge :tone="$email->statusTone()">{{ $email->statusLabel() }}</x-ui.badge>
                        </div>
                    @endforeach
                </x-ui.card>
            @endif
        </div>

        {{-- Money --}}
        <div class="space-y-4">
            <x-ui.card>
                <h2 class="mb-3 text-base font-semibold text-stone-900">{{ __('Payment') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-stone-600">{{ __('Bricks') }}</dt><dd><x-ui.money :amount="$sale->total" /></dd></div>
                    @if ($carriage->isPositive())
                        <div class="flex justify-between gap-3"><dt class="text-stone-600">{{ __('Carriage') }}</dt><dd><x-ui.money :amount="$carriage" /></dd></div>
                    @endif
                    @if ($sale->creditEntry)
                        <div class="flex justify-between gap-3"><dt class="text-stone-600">{{ __('Bricks not delivered') }} <span class="tabular text-xs" dir="ltr">{{ $sale->creditEntry->number }}</span></dt><dd><x-ui.money :amount="$sale->creditEntry->total->negate()" /></dd></div>
                    @endif
                    <div class="flex justify-between gap-3 border-t border-stone-200 pt-2 font-semibold"><dt>{{ __('Total') }}</dt><dd><x-ui.money :amount="$payment['due']" /></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-stone-600">{{ __('Paid') }}</dt><dd><x-ui.money :amount="$payment['paid']" /></dd></div>
                    <div class="flex justify-between gap-3 font-semibold"><dt>{{ __('Still to pay') }}</dt><dd><x-ui.money :amount="$payment['outstanding']" /></dd></div>
                </dl>
                <p class="mt-3 text-xs text-stone-500">{{ __('Money received for this sale pays it first. Other payments from the customer count towards their oldest unpaid sales first, as in a hand ledger.') }}</p>
                @php($received = collect([$sale->receiptEntry])->filter()->merge($sale->receipts->pluck('entry')))
                @if ($received->isNotEmpty())
                    <p class="mt-2 text-xs text-stone-600">
                        {{ __('Received for this sale') }}:
                        @foreach ($received as $receipt)
                            <a href="{{ route('vouchers.show', $receipt) }}" wire:navigate @class(['tabular font-medium text-brand-700 hover:underline', 'line-through' => $receipt->isReversed()]) dir="ltr">{{ $receipt->number }}</a> <x-ui.money :amount="$receipt->total" :currency="false" class="text-stone-500" />@if (! $loop->last)<span class="text-stone-300"> · </span>@endif
                        @endforeach
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Customer balance now') }}</p>
                <div class="mt-1"><x-party-balance :amount="$balance" class="justify-start!" /></div>
                <a href="{{ route('parties.show', $sale->customer) }}" wire:navigate class="mt-2 inline-block text-sm font-medium text-brand-700 hover:underline">{{ __('Open statement') }}</a>
            </x-ui.card>

            @if ($canAdjust && ($canClose || $canCancel))
                <x-ui.card>
                    <h2 class="mb-1 text-sm font-semibold text-stone-900">{{ __('Corrections') }}</h2>
                    @if ($canClose)
                        <p class="mb-2 text-xs text-stone-500">{{ __('The customer no longer takes the rest: a credit note takes it off what they owe.') }}</p>
                        <x-ui.button size="sm" variant="secondary" icon="circle-slash" wire:click="confirmClose">{{ __('Close the rest') }}</x-ui.button>
                    @endif
                    @if ($canCancel)
                        <p class="mb-2 text-xs text-stone-500">{{ __('Nothing was delivered: the whole sale can be cancelled. Money received stays on the customer\'s account.') }}</p>
                        <x-ui.button size="sm" variant="danger" icon="x" wire:click="confirmCancel">{{ __('Cancel sale') }}</x-ui.button>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- Record a delivery --}}
    @if ($canDeliver)
        <x-ui.modal wire:model="showDelivery" :title="__('Record delivery')" :description="__('The bricks that left the kiln on this trip. They come out of stock.')">
            <form id="delivery-form" wire:submit="saveDelivery" class="space-y-4">
                <x-ui.field :label="__('Date')" for="delivery-date" error="deliveryForm.dispatch_date" required>
                    <x-ui.input id="delivery-date" type="date" wire:model="deliveryForm.dispatch_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                <div class="space-y-2">
                    @error('deliveryForm.lines')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @foreach ($deliveryForm['lines'] ?? [] as $i => $row)
                        @php($line = $sale->lines->firstWhere('id', (int) $row['sale_line_id']))
                        @if ($line)
                            @php($left = Quantity::fromMilli($line->quantity->milli - ($this->delivered[$line->id] ?? 0)))
                            <x-ui.field :label="$line->product->displayName()" for="delivery-line-{{ $i }}" error="deliveryForm.lines.{{ $i }}.quantity" :hint="__('To deliver: :quantity', ['quantity' => $left->format($line->product->unit->decimals)])">
                                <x-ui.input id="delivery-line-{{ $i }}" wire:model="deliveryForm.lines.{{ $i }}.quantity" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="$line->product->unit->symbol" autocomplete="off" />
                            </x-ui.field>
                        @endif
                    @endforeach
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Driver')" for="delivery-driver" error="deliveryForm.driver_id" :hint="__('Only when the kiln arranged the transport.')">
                        <x-ui.select id="delivery-driver" wire:model="deliveryForm.driver_id" :placeholder="__('Customer\'s own transport')" :options="$this->drivers->mapWithKeys(fn ($d) => [$d->id => $d->displayName()])->all()" />
                    </x-ui.field>
                    <x-ui.field :label="__('Vehicle')" for="delivery-vehicle" error="deliveryForm.vehicle" :hint="__('Optional')">
                        <x-ui.input id="delivery-vehicle" wire:model="deliveryForm.vehicle" dir="auto" />
                    </x-ui.field>
                    <x-ui.field :label="__('Carriage')" for="delivery-carriage" error="deliveryForm.carriage" :hint="__('Charged to the customer and owed to the driver.')">
                        <x-ui.input id="delivery-carriage" wire:model="deliveryForm.carriage" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" :placeholder="__('None')" />
                    </x-ui.field>
                    <x-ui.field :label="__('Note')" for="delivery-notes" error="deliveryForm.notes" :hint="__('Optional')">
                        <x-ui.input id="delivery-notes" wire:model="deliveryForm.notes" />
                    </x-ui.field>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="delivery-form" icon="truck" loading="saveDelivery">{{ __('Save delivery') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Receive a payment --}}
    @if ($canReceive)
        <x-ui.modal wire:model="showPayment" :title="__('Receive payment')" :description="__('Money from :customer, recorded as a receipt voucher.', ['customer' => $sale->customer->displayName()])" max-width="md">
            <form id="payment-form" wire:submit="savePayment" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Amount')" for="payment-amount" error="paymentForm.amount" required>
                        <x-ui.input id="payment-amount" wire:model="paymentForm.amount" inputmode="decimal" dir="ltr" class="text-end tabular" :suffix="__('Rs')" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('Date')" for="payment-date" error="paymentForm.entry_date" required>
                        <x-ui.input id="payment-date" type="date" wire:model="paymentForm.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                    </x-ui.field>
                </div>
                <x-ui.field :label="__('Received into')" for="payment-account" error="paymentForm.account_id" required>
                    <x-ui.select id="payment-account" wire:model="paymentForm.account_id" :placeholder="__('Choose an account')" :options="$this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                </x-ui.field>
                <x-ui.field :label="__('Remarks')" for="payment-memo" error="paymentForm.memo" :hint="__('Optional')">
                    <x-ui.input id="payment-memo" wire:model="paymentForm.memo" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="payment-form" icon="check" loading="savePayment">{{ __('Save receipt') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Reverse a delivery / cancel / close: each needs a reason --}}
    @if ($canAdjust)
        @php($reasonTitle = match ($reasonFor) { 'reverse' => __('Reverse delivery'), 'cancel' => __('Cancel sale'), 'close' => __('Close the rest'), default => '' })
        @php($reasonText = match ($reasonFor) {
            'reverse' => __('The bricks go back into stock and are to be delivered again; carriage is cancelled. Both stay in the history.'),
            'cancel' => __('The sale is cancelled with a mirror entry and its bricks are free again. Money received stays on the customer\'s account.'),
            'close' => __('What was not delivered is taken off what the customer owes with a credit note, and those bricks are free again.'),
            default => '',
        })
        <x-ui.modal wire:model="showReason" :title="$reasonTitle" :description="$reasonText" max-width="md">
            <form id="reason-form" wire:submit="saveReason" class="space-y-4">
                <x-ui.field :label="__('Reason')" for="sale-reason" error="reasonForm.reason" required :hint="__('For example: entered twice, customer changed the order.')">
                    <x-ui.input id="sale-reason" wire:model="reasonForm.reason" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Back') }}</x-ui.button>
                <x-ui.button type="submit" form="reason-form" variant="danger" icon="check" loading="saveReason">{{ $reasonTitle }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
