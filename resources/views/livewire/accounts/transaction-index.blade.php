@php use App\Support\JournalPresenter; @endphp
<div>
    <x-ui.page-header :title="__('Transactions')" :description="__('Every money transaction, newest first. Mistakes are corrected by reversing; nothing is ever deleted.')" />

    <x-ui.table>
        <x-slot:toolbar>
            <div class="w-full sm:max-w-xs">
                <label for="tx-search" class="sr-only">{{ __('Search') }}</label>
                <x-ui.input id="tx-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number or note')" />
            </div>
            <div class="w-full sm:w-52">
                <label for="tx-type" class="sr-only">{{ __('Type') }}</label>
                <x-ui.select id="tx-type" wire:model.live="type" :options="$this->typeOptions" />
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Number') }}</x-ui.th>
            <x-ui.th class="min-w-64">{{ __('Description') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->entries as $entry)
            <tr wire:key="entry-{{ $entry->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $entry->isReversed()]) wire:loading.class="opacity-60" wire:target="type,search,gotoPage,nextPage,previousPage">
                <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $entry->entry_date->format('d-m-Y') }}</span></x-ui.td>
                <x-ui.td class="whitespace-nowrap"><span class="tabular font-medium text-stone-800" dir="ltr">{{ $entry->number }}</span></x-ui.td>
                <x-ui.td>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge :tone="JournalPresenter::typeTone($entry->type)">{{ $entry->type->label() }}</x-ui.badge>
                        @if ($entry->isReversed())
                            <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                        @endif
                        @if ($entry->reversalOf)
                            <span class="text-xs text-stone-500">{{ __('Reverses :number', ['number' => $entry->reversalOf->number]) }}</span>
                        @endif
                    </div>
                    <p class="mt-1 text-stone-700"><bdi>{{ JournalPresenter::describe($entry) }}</bdi></p>
                    <p class="mt-0.5 text-xs text-stone-400">{{ __('By :name', ['name' => $entry->creator?->name ?? __('System')]) }}</p>

                    @if ($expandedId === $entry->id)
                        <table class="mt-3 w-full max-w-xl text-xs">
                            <thead>
                                <tr class="text-stone-500">
                                    <th class="py-1 text-start font-medium">{{ __('Account') }}</th>
                                    <th class="py-1 text-end font-medium">{{ __('Debit') }}</th>
                                    <th class="py-1 text-end font-medium">{{ __('Credit') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($entry->lines as $line)
                                    <tr>
                                        <td class="py-1 text-stone-700">
                                            <span class="tabular text-stone-400" dir="ltr">{{ $line->account->code }}</span> {{ $line->account->displayName() }}
                                            @if ($line->party)
                                                <span class="text-stone-500">· <bdi>{{ $line->party->displayName() }}</bdi></span>
                                            @endif
                                            @if (filled($line->memo))
                                                <span class="block text-stone-400"><bdi>{{ $line->memo }}</bdi></span>
                                            @endif
                                        </td>
                                        <td class="py-1 text-end">@if ($line->debit->isPositive())<x-ui.money :amount="$line->debit" :currency="false" />@endif</td>
                                        <td class="py-1 text-end">@if ($line->credit->isPositive())<x-ui.money :amount="$line->credit" :currency="false" />@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-ui.td>
                <x-ui.td align="end" class="font-medium"><x-ui.money :amount="$entry->total" /></x-ui.td>
                <x-ui.td align="end">
                    <div class="flex items-center justify-end gap-1">
                        <x-ui.button size="sm" variant="ghost" :icon="$expandedId === $entry->id ? 'chevron-down' : 'receipt'" wire:click="toggle({{ $entry->id }})" :aria-expanded="$expandedId === $entry->id ? 'true' : 'false'">
                            <span class="hidden lg:inline">{{ __('Details') }}</span>
                        </x-ui.button>
                        @if ($canReverse && $entry->canBeReversed())
                            <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $entry->id }})" :aria-label="__('Reverse :number', ['number' => $entry->number])" :title="__('Reverse')" />
                        @endif
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <tr><td colspan="5"><x-ui.empty-state icon="receipt" :title="__('No transactions found')" :description="__('Payments, receipts, expenses, transfers and opening balances will appear here.')" /></td></tr>
        @endforelse

        @if ($this->entries->hasPages())
            <x-slot:footer>{{ $this->entries->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    @if ($canReverse)
    <x-ui.modal wire:model="showReverseForm" :title="__('Reverse transaction')" :description="$this->reversing ? __('A mirror entry will cancel :number. Both stay in the history.', ['number' => $this->reversing->number]) : null" max-width="md">
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
