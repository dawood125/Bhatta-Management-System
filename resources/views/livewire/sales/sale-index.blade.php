@php use App\Enums\PaymentStatus; use App\Enums\SaleStatus; @endphp
<div>
    <x-ui.page-header :title="__('Sales')" :description="__('Bricks sold to customers: what each one owes, has paid and still has to collect.')">
        @if ($canRecord)
            <x-slot:actions>
                <x-ui.button icon="plus" :href="route('sales.create')" wire:navigate>{{ __('New sale') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-sales.tabs active="sales" />

    <x-ui.card class="mb-4">
        <x-date-range id="sales-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Sold') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->summary['total']" /></p>
                <p class="text-xs text-stone-500">{{ __('With carriage, less bricks not delivered. Cancelled sales are not counted.') }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Still to be paid') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$this->summary['outstanding']" /></p>
                <p class="text-xs text-stone-500">{{ __('Of these sales, paid oldest first from each customer\'s account.') }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Waiting for delivery') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900 tabular">{{ number_format($this->summary['open']) }}</p>
                <p class="text-xs text-stone-500">{{ __('Sales whose bricks are not all collected yet.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="grid w-full gap-2 sm:grid-cols-3 lg:max-w-3xl">
                    <div>
                        <label for="sales-search" class="sr-only">{{ __('Search') }}</label>
                        <x-ui.input id="sales-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number, customer or note')" />
                    </div>
                    <div>
                        <label for="sales-status" class="sr-only">{{ __('Delivery') }}</label>
                        <x-ui.select id="sales-status" wire:model.live="status" :placeholder="__('All deliveries')" :options="SaleStatus::options()" />
                    </div>
                    <div>
                        <label for="sales-payment" class="sr-only">{{ __('Payment') }}</label>
                        <x-ui.select id="sales-payment" wire:model.live="payment" :placeholder="__('All payments')" :options="PaymentStatus::options()" />
                    </div>
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-44">{{ __('Customer') }}</x-ui.th>
                <x-ui.th class="min-w-40">{{ __('Bricks') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Total') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Still to pay') }}</x-ui.th>
                <x-ui.th>{{ __('Status') }}</x-ui.th>
            </x-slot:head>

            @forelse ($this->sales as $sale)
                @php($pay = $this->payments[$sale->id] ?? null)
                <tr wire:key="sale-{{ $sale->id }}" @class(['transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $sale->isCancelled()]) wire:loading.class="opacity-60" wire:target="search,status,payment,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $sale->sale_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="tabular font-medium text-brand-700 hover:underline" dir="ltr">{{ $sale->number }}</a>
                    </x-ui.td>
                    <x-ui.td>
                        <a href="{{ route('parties.show', $sale->customer) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $sale->customer->displayName() }}</a>
                        @if ($sale->agent)
                            <p class="text-xs text-stone-500">{{ __('Agent: :name', ['name' => $sale->agent->displayName()]) }}</p>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="text-sm text-stone-600">
                        @foreach ($sale->lines as $line)
                            <span class="whitespace-nowrap">{{ $line->product->displayName() }} <bdi class="tabular">{{ $line->quantity->format($line->product->unit->decimals) }}</bdi></span>@if (! $loop->last)<span class="text-stone-300"> · </span>@endif
                        @endforeach
                    </x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $sale->isCancelled()])><x-ui.money :amount="$pay['due'] ?? $sale->total" :currency="false" /></x-ui.td>
                    <x-ui.td align="end">
                        @if ($pay && ! $sale->isCancelled())
                            <x-ui.money :amount="$pay['outstanding']" :currency="false" :muted="$pay['outstanding']->isZero()" />
                        @else
                            <span class="text-stone-400">—</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        <div class="flex flex-wrap gap-1">
                            <x-ui.badge :tone="$sale->status->tone()">{{ $sale->status->label() }}</x-ui.badge>
                            @if ($pay && $pay['status'])
                                <x-ui.badge :tone="$pay['status']->tone()">{{ $pay['status']->label() }}</x-ui.badge>
                            @endif
                        </div>
                    </x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state icon="shopping-cart" :title="__('No sales in this period')" :description="$search !== '' || $status !== '' || $payment !== '' ? __('Try a different search or filter.') : __('Record a sale when a customer orders bricks; deliveries can follow over several trips.')">
                            @if ($canRecord && $search === '' && $status === '' && $payment === '')
                                <x-ui.button icon="plus" variant="secondary" :href="route('sales.create')" wire:navigate>{{ __('New sale') }}</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse

            @if ($this->sales->hasPages())
                <x-slot:footer>{{ $this->sales->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif
</div>
