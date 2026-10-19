@php use App\Models\MaterialPurchase; use App\Models\MaterialPurchaseCharge; @endphp
@php($entry = $purchase->entry)
@php($stock = app(\App\Materials\MaterialStock::class))
<div class="mx-auto max-w-4xl">
    <div class="mb-2 print:hidden">
        <a href="{{ route('materials.purchases') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Purchases') }}
        </a>
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-end gap-2 print:hidden">
        <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
        @if ($canReverse && ! $entry->isReversed())
            <x-ui.button variant="secondary" icon="undo-2" wire:click="confirmReverse({{ $entry->id }})">{{ __('Reverse') }}</x-ui.button>
        @endif
        @if ($canRecord)
            <x-ui.button icon="truck" :href="route('materials.purchases.create')" wire:navigate>{{ __('New purchase') }}</x-ui.button>
        @endif
    </div>

    <x-ui.card class="print:shadow-none print:ring-0">
        <div class="flex flex-col gap-4 border-b border-stone-100 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-stone-600">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">{{ __('Material purchase') }}</h1>
                @if ($entry->isReversed())
                    <div class="mt-2"><x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge></div>
                @endif
            </div>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:text-end">
                <dt class="text-stone-500">{{ __('Number') }}</dt>
                <dd class="tabular font-semibold text-stone-900" dir="ltr">{{ $entry->number }}</dd>
                <dt class="text-stone-500">{{ __('Date') }}</dt>
                <dd class="tabular text-stone-900" dir="ltr">{{ $purchase->purchase_date->format('d-m-Y') }}</dd>
                <dt class="text-stone-500">{{ __('Supplier') }}</dt>
                <dd class="text-stone-900">
                    @if ($purchase->supplier)
                        <a href="{{ route('parties.show', $purchase->supplier) }}" wire:navigate class="hover:underline print:no-underline"><bdi>{{ $purchase->supplier->displayName() }}</bdi></a>
                    @else
                        {{ __('No supplier') }}
                    @endif
                </dd>
                <dt class="text-stone-500">{{ __('Payment') }}</dt>
                <dd class="text-stone-900">{{ $purchase->payment === MaterialPurchase::PAID_NOW ? __('Paid from :account', ['account' => $purchase->treasuryAccount?->displayName()]) : __('On credit') }}</dd>
                @if (filled($purchase->reference))
                    <dt class="text-stone-500">{{ __('Bill / vehicle number') }}</dt>
                    <dd class="text-stone-900"><bdi>{{ $purchase->reference }}</bdi></dd>
                @endif
            </dl>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-xs uppercase tracking-wide text-stone-500">
                        <th class="py-2 pe-3 text-start font-medium">{{ __('Material') }}</th>
                        <th class="py-2 pe-3 text-end font-medium">{{ __('Quantity') }}</th>
                        <th class="py-2 pe-3 text-end font-medium">{{ __('Rate') }}</th>
                        <th class="py-2 pe-3 text-end font-medium">{{ __('Amount') }}</th>
                        <th class="py-2 pe-3 text-end font-medium">{{ __('Charges') }}</th>
                        <th class="py-2 text-end font-medium">{{ __('Cost') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($purchase->lines as $line)
                        @php($unit = $line->material->unit->symbol ?? $line->material->unit->displayName())
                        @php($perUnit = $stock->averageCost(['quantity' => $line->quantity, 'value' => $line->cost]))
                        <tr class="align-top">
                            <td class="py-2.5 pe-3">
                                <a href="{{ route('materials.show', $line->material) }}" wire:navigate class="font-medium text-stone-900 hover:underline print:no-underline">{{ $line->material->displayName() }}</a>
                            </td>
                            <td class="py-2.5 pe-3 text-end whitespace-nowrap"><span class="tabular" dir="ltr">{{ $line->quantity->format($line->material->unit->decimals) }}</span> <span class="text-xs text-stone-500">{{ $unit }}</span></td>
                            <td class="py-2.5 pe-3 text-end whitespace-nowrap"><x-ui.money :amount="$line->rate" :currency="false" /></td>
                            <td class="py-2.5 pe-3 text-end"><x-ui.money :amount="$line->amount" :currency="false" /></td>
                            <td class="py-2.5 pe-3 text-end"><x-ui.money :amount="$line->charges" :currency="false" :muted="true" /></td>
                            <td class="py-2.5 text-end">
                                <x-ui.money :amount="$line->cost" class="font-medium" />
                                @if ($perUnit)
                                    <span class="block text-xs text-stone-500">{{ __(':amount per :unit', ['amount' => $perUnit->formatWithCurrency(), 'unit' => $unit]) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($purchase->charges->isNotEmpty())
            <h2 class="mt-6 text-sm font-semibold text-stone-900">{{ __('Extra charges') }}</h2>
            <ul class="mt-2 divide-y divide-stone-100 text-sm">
                @foreach ($purchase->charges as $charge)
                    <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-2">
                        <div>
                            <p class="text-stone-900"><bdi>{{ $charge->label() }}</bdi></p>
                            <p class="text-xs text-stone-500">
                                @switch($charge->payee)
                                    @case(MaterialPurchaseCharge::TO_PARTY)
                                        {{ __('Owed to :name', ['name' => $charge->party?->displayName()]) }}
                                        @break
                                    @case(MaterialPurchaseCharge::FROM_ACCOUNT)
                                        {{ __('Paid from :account', ['account' => $charge->treasuryAccount?->displayName()]) }}
                                        @break
                                    @default
                                        {{ $purchase->payment === MaterialPurchase::PAID_NOW ? __('Paid with the goods') : __('On the supplier\'s bill') }}
                                @endswitch
                            </p>
                        </div>
                        <x-ui.money :amount="$charge->amount" />
                    </li>
                @endforeach
            </ul>
        @endif

        <dl class="mt-4 space-y-1 border-t-2 border-stone-200 pt-3 text-sm">
            <div class="flex justify-between"><dt class="text-stone-600">{{ __('Goods') }}</dt><dd><x-ui.money :amount="$purchase->goods_total" /></dd></div>
            <div class="flex justify-between"><dt class="text-stone-600">{{ __('Charges') }}</dt><dd><x-ui.money :amount="$purchase->charges_total" /></dd></div>
            <div class="flex justify-between text-base font-semibold text-stone-900"><dt>{{ __('Total cost') }}</dt><dd><x-ui.money :amount="$purchase->total" /></dd></div>
        </dl>

        @if (filled($purchase->notes))
            <p class="mt-3 text-sm text-stone-700"><span class="text-stone-500">{{ __('Note') }}:</span> <bdi>{{ $purchase->notes }}</bdi></p>
        @endif

        <p class="mt-4 text-xs text-stone-500">
            {{ __('Recorded by :name on :date', ['name' => $purchase->creator?->name ?? __('System'), 'date' => $purchase->created_at?->timezone(config('app.timezone'))->format('d-m-Y g:i A')]) }}
        </p>

        @if ($entry->reversedBy)
            <div class="mt-4 rounded-[var(--radius-control)] bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                <p class="font-medium">{{ __('Reversed by :number on :date', ['number' => $entry->reversedBy->number, 'date' => $entry->reversedBy->entry_date->format('d-m-Y')]) }}</p>
                <p class="mt-0.5"><span class="text-red-700">{{ __('Reason') }}:</span> <bdi>{{ $entry->reversedBy->memo }}</bdi></p>
                <p class="mt-0.5 text-xs text-red-700">{{ __('By :name', ['name' => $entry->reversedBy->creator?->name ?? __('System')]) }}</p>
            </div>
        @endif

        <div class="mt-16 hidden grid-cols-3 gap-8 text-center text-xs text-stone-600 print:grid">
            <div class="border-t border-stone-400 pt-1">{{ __('Prepared by') }}</div>
            <div class="border-t border-stone-400 pt-1">{{ __('Received by') }}</div>
            <div class="border-t border-stone-400 pt-1">{{ __('Approved by') }}</div>
        </div>
    </x-ui.card>

    <x-materials.reverse-modal :number="$this->reversingNumber()" />
</div>
