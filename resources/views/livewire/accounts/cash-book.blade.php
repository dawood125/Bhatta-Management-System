@php use App\Support\JournalPresenter; @endphp
<div>
    <div class="mb-2">
        <a href="{{ route('accounts.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Cash and bank') }}
        </a>
    </div>

    <x-ui.page-header :title="__('Cash book').': '.$account->displayName()" :description="$account->kind->label()" />

    <x-ui.card class="mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid grid-cols-2 gap-3 sm:w-96">
                <div>
                    <label for="book-from" class="mb-1 block text-xs font-medium text-stone-500">{{ __('From date') }}</label>
                    <x-ui.input id="book-from" type="date" wire:model.live="from" dir="ltr" />
                </div>
                <div>
                    <label for="book-to" class="mb-1 block text-xs font-medium text-stone-500">{{ __('To date') }}</label>
                    <x-ui.input id="book-to" type="date" wire:model.live="to" dir="ltr" />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button size="sm" variant="secondary" wire:click="setRange('today')">{{ __('Today') }}</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="setRange('this_month')">{{ __('This month') }}</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="setRange('last_month')">{{ __('Last month') }}</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="setRange('all')">{{ __('All') }}</x-ui.button>
            </div>
        </div>
    </x-ui.card>

    @php($statement = $this->statement)

    @if ($statement === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-4" wire:loading.class="opacity-60" wire:target="from,to,setRange">
            @foreach ([[__('Opening balance'), $statement['opening']], [__('Money in'), $statement['total_in']], [__('Money out'), $statement['total_out']], [__('Closing balance'), $statement['closing']]] as [$label, $value])
                <x-ui.card class="py-4!">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$value" /></p>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.table wire:loading.class="opacity-60" wire:target="from,to,setRange">
            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-64">{{ __('Description') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Money in') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Money out') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
            </x-slot:head>

            <tr class="bg-stone-50/60">
                <x-ui.td colspan="5" class="font-medium text-stone-600">{{ __('Opening balance') }}</x-ui.td>
                <x-ui.td align="end" class="font-semibold"><x-ui.money :amount="$statement['opening']" :currency="false" /></x-ui.td>
            </tr>

            @forelse ($statement['rows'] as $row)
                @php($entry = $row['line']->entry)
                <tr wire:key="line-{{ $row['line']->id }}" @class(['bg-red-50/40' => $entry->isReversed() || $entry->type === \App\Enums\JournalType::Reversal])>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $entry->entry_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular text-stone-600" dir="ltr">{{ $entry->number }}</span></x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="JournalPresenter::typeTone($entry->type)">{{ $entry->type->label() }}</x-ui.badge>
                            @if ($entry->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-stone-700"><bdi>{{ JournalPresenter::describe($entry) }}</bdi></p>
                    </x-ui.td>
                    <x-ui.td align="end">@if ($row['in']->isPositive())<x-ui.money :amount="$row['in']" :currency="false" class="text-emerald-700" />@endif</x-ui.td>
                    <x-ui.td align="end">@if ($row['out']->isPositive())<x-ui.money :amount="$row['out']" :currency="false" />@endif</x-ui.td>
                    <x-ui.td align="end" class="font-medium"><x-ui.money :amount="$row['balance']" :currency="false" /></x-ui.td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="book-open" :title="__('No transactions in this period')" /></td></tr>
            @endforelse

            <tr class="bg-stone-50/80 font-semibold">
                <x-ui.td colspan="3">{{ __('Closing balance') }}</x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['total_in']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['total_out']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['closing']" :currency="false" /></x-ui.td>
            </tr>
        </x-ui.table>
    @endif
</div>
