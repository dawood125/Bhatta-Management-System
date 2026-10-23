@php use App\Support\JournalPresenter; use App\Enums\JournalType; @endphp
@php($isPayment = $entry->type === JournalType::Payment)
<div class="mx-auto max-w-3xl">
    <div class="mb-2 print:hidden">
        <a href="{{ route('vouchers.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Payments and receipts') }}
        </a>
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-end gap-2 print:hidden">
        <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
        @if ($canReverse && $entry->canBeReversed())
            <x-ui.button variant="secondary" icon="undo-2" wire:click="confirmReverse">{{ __('Reverse') }}</x-ui.button>
        @endif
        @if ($canRecord)
            <x-ui.button :icon="$isPayment ? 'arrow-up-right' : 'arrow-down-left'" :href="route('vouchers.create', $entry->type->value)" wire:navigate>{{ $isPayment ? __('New payment') : __('New receipt') }}</x-ui.button>
        @endif
    </div>

    <x-ui.card class="print:shadow-none print:ring-0">
        {{-- Heading --}}
        <div class="flex flex-col gap-4 border-b border-stone-100 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-stone-600">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">{{ $isPayment ? __('Payment voucher') : __('Receipt voucher') }}</h1>
                @if ($entry->isReversed())
                    <div class="mt-2"><x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge></div>
                @endif
            </div>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:text-end">
                <dt class="text-stone-500">{{ __('Number') }}</dt>
                <dd class="tabular font-semibold text-stone-900" dir="ltr">{{ $entry->number }}</dd>
                <dt class="text-stone-500">{{ __('Date') }}</dt>
                <dd class="tabular text-stone-900" dir="ltr">{{ $entry->entry_date->format('d-m-Y') }}</dd>
                <dt class="text-stone-500">{{ $isPayment ? __('Paid from') : __('Received into') }}</dt>
                <dd class="text-stone-900">{{ $main?->account->displayName() }}</dd>
            </dl>
        </div>

        {{-- Lines --}}
        <table class="mt-4 w-full text-sm">
            <thead>
                <tr class="border-b border-stone-200 text-xs uppercase tracking-wide text-stone-500">
                    <th class="py-2 pe-3 text-start font-medium">#</th>
                    <th class="py-2 pe-3 text-start font-medium">{{ $isPayment ? __('Paid to') : __('Received from') }}</th>
                    <th class="py-2 pe-3 text-start font-medium">{{ __('Remarks') }}</th>
                    <th class="py-2 text-end font-medium">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($lines as $index => $line)
                    <tr class="align-top">
                        <td class="py-2.5 pe-3 tabular text-stone-500">{{ $index + 1 }}</td>
                        <td class="py-2.5 pe-3">
                            @if ($line->party)
                                <a href="{{ route('parties.show', $line->party) }}" wire:navigate class="font-medium text-stone-900 hover:underline print:no-underline"><bdi>{{ $line->party->displayName() }}</bdi></a>
                            @else
                                <span class="font-medium text-stone-900">{{ $line->account->displayName() }}</span>
                                <span class="block text-xs text-stone-500">{{ __('Our account') }}</span>
                            @endif
                        </td>
                        <td class="py-2.5 pe-3 text-stone-600"><bdi>{{ $line->memo }}</bdi></td>
                        <td class="py-2.5 text-end"><x-ui.money :amount="$line->debit->isPositive() ? $line->debit : $line->credit" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-stone-200">
                    <td colspan="3" class="py-3 font-semibold text-stone-900">{{ __('Total') }}</td>
                    <td class="py-3 text-end text-base font-semibold text-stone-900"><x-ui.money :amount="$entry->total" /></td>
                </tr>
            </tfoot>
        </table>

        @if (filled($entry->memo))
            <p class="mt-3 text-sm text-stone-700"><span class="text-stone-500">{{ __('Note') }}:</span> <bdi>{{ $entry->memo }}</bdi></p>
        @endif

        <p class="mt-4 text-xs text-stone-500">
            {{ __('Recorded by :name on :date', ['name' => $entry->creator?->name ?? __('System'), 'date' => $entry->created_at?->timezone(config('app.timezone'))->format('d-m-Y g:i A')]) }}
        </p>

        @if ($entry->reversedBy)
            <div class="mt-4 rounded-[var(--radius-control)] bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                <p class="font-medium">{{ __('Reversed by :number on :date', ['number' => $entry->reversedBy->number, 'date' => $entry->reversedBy->entry_date->format('d-m-Y')]) }}</p>
                <p class="mt-0.5"><span class="text-red-700">{{ __('Reason') }}:</span> <bdi>{{ $entry->reversedBy->memo }}</bdi></p>
                <p class="mt-0.5 text-xs text-red-700">{{ __('By :name', ['name' => $entry->reversedBy->creator?->name ?? __('System')]) }}</p>
            </div>
        @endif

        {{-- Signature lines (printed slips) --}}
        <div class="mt-16 hidden grid-cols-3 gap-8 text-center text-xs text-stone-600 print:grid">
            <div class="border-t border-stone-400 pt-1">{{ __('Prepared by') }}</div>
            <div class="border-t border-stone-400 pt-1">{{ $isPayment ? __('Received by') : __('Paid by') }}</div>
            <div class="border-t border-stone-400 pt-1">{{ __('Approved by') }}</div>
        </div>
    </x-ui.card>

    @if ($canReverse)
        <x-ui.modal wire:model="showReverseForm" :title="__('Reverse voucher')" :description="__('A mirror entry will cancel :number. Both stay in the history.', ['number' => $entry->number])" max-width="md">
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
