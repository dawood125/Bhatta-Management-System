@php use App\Support\JournalPresenter; use App\Enums\JournalType; @endphp
<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Payments and receipts')" :description="__('Money paid to and received from parties, one voucher per account.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
                @if ($canRecord)
                    <x-ui.button variant="secondary" icon="arrow-down-left" :href="route('vouchers.create', 'receipt')" wire:navigate>{{ __('New receipt') }}</x-ui.button>
                    <x-ui.button icon="arrow-up-right" :href="route('vouchers.create', 'payment')" wire:navigate>{{ __('New payment') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </div>

    {{-- Printed heading --}}
    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Payments and receipts') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="vouchers-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:max-w-3xl">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total paid') }}</p>
                <p class="mt-1 text-lg font-semibold text-amber-800"><x-ui.money :amount="$this->totals['paid']" /></p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total received') }}</p>
                <p class="mt-1 text-lg font-semibold text-emerald-800"><x-ui.money :amount="$this->totals['received']" /></p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Vouchers') }}</p>
                <p class="mt-1 text-lg font-semibold text-stone-900 tabular">{{ number_format($this->totals['count']) }}</p>
                <p class="text-xs text-stone-500">{{ __('Reversed vouchers are not counted.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:max-w-xs print:hidden">
                    <label for="vouchers-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="vouchers-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number, party or note')" />
                </div>
                <div class="grid w-full grid-cols-2 gap-3 sm:w-auto print:hidden">
                    <div class="sm:w-40">
                        <label for="vouchers-type" class="sr-only">{{ __('Type') }}</label>
                        <x-ui.select id="vouchers-type" wire:model.live="type" :options="['' => __('All vouchers'), 'payment' => __('Payments'), 'receipt' => __('Receipts')]" />
                    </div>
                    <div class="sm:w-48">
                        <label for="vouchers-account" class="sr-only">{{ __('Account') }}</label>
                        <x-ui.select id="vouchers-account" wire:model.live="account" :placeholder="__('All accounts')" :options="$this->accountOptions" />
                    </div>
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-64">{{ __('Paid to / received from') }}</x-ui.th>
                <x-ui.th>{{ __('Account') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
                <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
            </x-slot:head>

            @forelse ($this->vouchers as $voucher)
                @php($main = $voucher->lines->firstWhere('line_no', 1))
                <tr wire:key="voucher-{{ $voucher->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $voucher->isReversed()]) wire:loading.class="opacity-60" wire:target="type,account,search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $voucher->entry_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap">
                        <a href="{{ route('vouchers.show', $voucher) }}" wire:navigate class="tabular font-medium text-stone-800 hover:underline" dir="ltr">{{ $voucher->number }}</a>
                    </x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="JournalPresenter::typeTone($voucher->type)" :icon="$voucher->type === JournalType::Payment ? 'arrow-up-right' : 'arrow-down-left'">{{ $voucher->type->label() }}</x-ui.badge>
                            @if ($voucher->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        <ul class="mt-1 space-y-0.5 text-stone-700">
                            @foreach ($voucher->lines->where('line_no', '>', 1) as $line)
                                <li class="flex flex-wrap justify-between gap-x-4">
                                    <bdi>{{ JournalPresenter::lineName($line) }}</bdi>
                                    @if ($voucher->lines->count() > 2)
                                        <x-ui.money :amount="$line->debit->isPositive() ? $line->debit : $line->credit" :currency="false" class="text-xs text-stone-500" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if (filled($voucher->memo))
                            <p class="mt-0.5 text-xs text-stone-500"><bdi>{{ $voucher->memo }}</bdi></p>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="whitespace-nowrap">{{ $main?->account->displayName() }}</x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $voucher->isReversed()])><x-ui.money :amount="$voucher->total" /></x-ui.td>
                    <x-ui.td align="end" class="print:hidden">
                        <div class="flex items-center justify-end gap-1">
                            <x-ui.button size="sm" variant="ghost" icon="eye" :href="route('vouchers.show', $voucher)" wire:navigate :aria-label="__('Open :number', ['number' => $voucher->number])" :title="__('Open')" />
                            @if ($canReverse && $voucher->canBeReversed())
                                <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $voucher->id }})" :aria-label="__('Reverse :number', ['number' => $voucher->number])" :title="__('Reverse')" />
                            @endif
                        </div>
                    </x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state icon="arrow-left-right" :title="__('No vouchers in this period')" :description="$search !== '' || $type !== '' || $account !== '' ? __('Try a different search or filter.') : __('Record money paid to suppliers and drivers, or received from customers.')">
                            @if ($canRecord && $search === '' && $type === '' && $account === '')
                                <x-ui.button icon="arrow-up-right" variant="secondary" :href="route('vouchers.create', 'payment')" wire:navigate>{{ __('New payment') }}</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse

            @if ($this->vouchers->hasPages())
                <x-slot:footer>{{ $this->vouchers->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif

    @if ($canReverse)
        <x-ui.modal wire:model="showReverseForm" :title="__('Reverse voucher')" :description="$this->reversing ? __('A mirror entry will cancel :number. Both stay in the history.', ['number' => $this->reversing->number]) : null" max-width="md">
            <form id="reverse-form" wire:submit="reverse" class="space-y-4">
                <x-ui.field :label="__('Reason')" for="reverse-reason" error="reverseForm.reason" required :hint="__('For example: wrong amount, entered twice.')">
                    <x-ui.input id="reverse-reason" wire:model="reverseForm.reason" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="reverse-form" variant="danger" icon="undo-2" loading="reverse">{{ __('Reverse') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
