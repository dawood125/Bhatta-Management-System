@php use App\Support\JournalPresenter; @endphp
<div>
    <div class="mb-2 print:hidden">
        <a href="{{ route('parties.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Parties') }}
        </a>
    </div>

    {{-- Heading (also the printed header) --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="hidden text-sm font-semibold text-stone-700 print:block">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ __('Statement') }}: {{ $party->displayName() }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-500">
                @foreach ($roles as $partyRole)
                    <x-ui.badge :tone="$partyRole->tone()">{{ $partyRole->label() }}</x-ui.badge>
                @endforeach
                @if ($party->phone)
                    <span class="tabular" dir="ltr">{{ $party->phone }}</span>
                @endif
                @if ($party->area)
                    <span>{{ $party->area->displayName() }}</span>
                @endif
                @if ($party->salesAgent)
                    <span>{{ __('Agent: :name', ['name' => $party->salesAgent->displayName()]) }}</span>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Current balance') }}</p>
            <x-party-balance :amount="$this->currentBalance" size="lg" />
            <x-ui.button size="sm" variant="secondary" icon="printer" x-on:click="window.print()" class="print:hidden">{{ __('Print') }}</x-ui.button>
        </div>
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid grid-cols-2 gap-3 sm:w-96">
                <div>
                    <label for="statement-from" class="mb-1 block text-xs font-medium text-stone-500">{{ __('From date') }}</label>
                    <x-ui.input id="statement-from" type="date" wire:model.live="from" dir="ltr" />
                </div>
                <div>
                    <label for="statement-to" class="mb-1 block text-xs font-medium text-stone-500">{{ __('To date') }}</label>
                    <x-ui.input id="statement-to" type="date" wire:model.live="to" dir="ltr" />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
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
        <p class="mb-2 hidden text-sm text-stone-600 print:block">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ \Illuminate\Support\Carbon::parse($from)->format('d-m-Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d-m-Y') }}</span></p>

        <x-ui.table wire:loading.class="opacity-60" wire:target="from,to,setRange">
            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-64">{{ __('Description') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Debit (banam)') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Credit (jama)') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
            </x-slot:head>

            <tr class="bg-stone-50/60">
                <x-ui.td colspan="5" class="font-medium text-stone-600">{{ __('Balance brought forward') }}</x-ui.td>
                <x-ui.td align="end"><x-party-balance :amount="$statement['opening']" /></x-ui.td>
            </tr>

            @forelse ($statement['rows'] as $row)
                @php($entry = $row['line']->entry)
                <tr wire:key="pline-{{ $row['line']->id }}" @class(['bg-red-50/40' => $entry->isReversed() || $entry->type === \App\Enums\JournalType::Reversal])>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $entry->entry_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular text-stone-600" dir="ltr">{{ $entry->number }}</span></x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="JournalPresenter::typeTone($entry->type)">{{ $entry->type->label() }}</x-ui.badge>
                            @if ($entry->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        @php($description = JournalPresenter::describe($entry))
                        @if ($description !== $entry->type->label())
                            <p class="mt-1 text-stone-700"><bdi>{{ $description }}</bdi></p>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end">@if ($row['debit']->isPositive())<x-ui.money :amount="$row['debit']" :currency="false" />@endif</x-ui.td>
                    <x-ui.td align="end">@if ($row['credit']->isPositive())<x-ui.money :amount="$row['credit']" :currency="false" />@endif</x-ui.td>
                    <x-ui.td align="end"><x-party-balance :amount="$row['balance']" /></x-ui.td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="book-open" :title="__('No transactions in this period')" /></td></tr>
            @endforelse

            <tr class="bg-stone-50/80 font-semibold">
                <x-ui.td colspan="3">{{ __('Closing balance') }}</x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['total_debit']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['total_credit']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-party-balance :amount="$statement['closing']" /></x-ui.td>
            </tr>
        </x-ui.table>

        <p class="mt-3 hidden text-xs text-stone-500 print:block">{{ __('Printed on :date', ['date' => now()->format('d-m-Y g:i A')]) }}</p>
    @endif
</div>
