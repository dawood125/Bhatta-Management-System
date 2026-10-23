<div>
    <div class="mb-2 print:hidden">
        <a href="{{ route('labour.settlements') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Settlements') }}
        </a>
    </div>

    <div class="print:hidden">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight text-stone-900">
                    {{ __('Settlement') }} <span class="tabular" dir="ltr">{{ $settlement->number }}</span>
                    @if ($settlement->isReversed())
                        <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                    @endif
                </h1>
                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-stone-500">
                    <span>{{ __('Paid on') }} <span class="tabular" dir="ltr">{{ $settlement->settlement_date->format('d-m-Y') }}</span></span>
                    <span>{{ __('Period') }} <span class="tabular" dir="ltr">{{ $settlement->period_from->format('d-m-Y') }} – {{ $settlement->period_to->format('d-m-Y') }}</span></span>
                    <span>{{ __('From :account', ['account' => $settlement->treasuryAccount->displayName()]) }}</span>
                    <span>{{ __('By :name', ['name' => $settlement->creator?->name ?? __('System')]) }}</span>
                </div>
                @if (filled($settlement->notes))
                    <p class="mt-2 text-stone-700"><bdi>{{ $settlement->notes }}</bdi></p>
                @endif
                @if ($settlement->entry?->reversedBy)
                    <p class="mt-2 text-sm text-red-700">{{ __('Reversed by :number', ['number' => $settlement->entry->reversedBy->number]) }}@if ($settlement->entry->reversedBy->memo): <bdi>{{ $settlement->entry->reversedBy->memo }}</bdi>@endif</p>
                @endif
            </div>
            <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total paid') }}</p>
                <x-ui.money :amount="$settlement->total_paid" class="text-2xl font-semibold text-stone-900" />
                <div class="flex gap-2">
                    <x-ui.button size="sm" variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print payslips') }}</x-ui.button>
                    @if ($canReverse)
                        <x-ui.button size="sm" variant="secondary" icon="undo-2" wire:click="confirmReverse">{{ __('Reverse') }}</x-ui.button>
                    @endif
                </div>
            </div>
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th class="min-w-48">{{ __('Worker') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Brought forward') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Earned') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Advances') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Deductions') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Payable') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Paid') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Remaining') }}</x-ui.th>
            </x-slot:head>

            @foreach ($settlement->lines as $line)
                <tr wire:key="sline-{{ $line->id }}">
                    <x-ui.td>
                        <a href="{{ route('labour.workers.show', $line->worker) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $line->worker->displayName() }}</a>
                        <p class="text-xs text-stone-500">{{ $line->worker->category->displayName() }}</p>
                    </x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$line->brought_forward" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$line->earned" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$line->advances" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$line->deductions" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$line->payable" :currency="false" /></x-ui.td>
                    <x-ui.td align="end" class="font-semibold"><x-ui.money :amount="$line->paid" :currency="false" /></x-ui.td>
                    <x-ui.td align="end"><x-worker-balance :amount="$line->remaining" /></x-ui.td>
                </tr>
            @endforeach
        </x-ui.table>
        <p class="mt-3 text-xs text-stone-500">{{ __('Brought forward + earned − advances − deductions = payable. Positive amounts are owed to the worker; negative ones are owed by the worker.') }}</p>
    </div>

    {{-- Printed: one payslip per worker --}}
    <div class="hidden print:block">
        @foreach ($settlement->lines as $line)
            <section class="mb-6 break-inside-avoid rounded border border-stone-400 p-4 text-sm">
                <div class="mb-3 flex items-start justify-between gap-4 border-b border-stone-300 pb-2">
                    <div>
                        <p class="font-semibold text-stone-900">{{ $kiln->displayName() }}</p>
                        <p class="text-stone-700">{{ __('Payslip') }} · <span dir="ltr">{{ $settlement->number }}</span>@if ($settlement->isReversed()) · {{ __('Reversed') }}@endif</p>
                    </div>
                    <div class="text-end text-stone-700">
                        <p>{{ __('Paid on') }} <span class="tabular" dir="ltr">{{ $settlement->settlement_date->format('d-m-Y') }}</span></p>
                        <p>{{ __('Period') }} <span class="tabular" dir="ltr">{{ $settlement->period_from->format('d-m-Y') }} – {{ $settlement->period_to->format('d-m-Y') }}</span></p>
                    </div>
                </div>
                <p class="mb-2 text-base font-semibold text-stone-900">{{ $line->worker->displayName() }} <span class="text-sm font-normal text-stone-600">· {{ $line->worker->category->displayName() }}</span></p>
                <table class="w-full">
                    <tbody>
                        @foreach ([
                            [__('Brought forward'), $line->brought_forward],
                            [__('Earned in the period'), $line->earned],
                            [__('Advances'), $line->advances->negate()],
                            [__('Deductions'), $line->deductions->negate()],
                        ] as [$label, $amount])
                            <tr><td class="py-0.5 text-stone-700">{{ $label }}</td><td class="py-0.5 text-end"><x-ui.money :amount="$amount" /></td></tr>
                        @endforeach
                        <tr class="border-t border-stone-300 font-semibold"><td class="py-1">{{ __('Payable') }}</td><td class="py-1 text-end"><x-ui.money :amount="$line->payable" /></td></tr>
                        <tr class="font-semibold"><td class="py-0.5">{{ __('Paid') }}</td><td class="py-0.5 text-end"><x-ui.money :amount="$line->paid" /></td></tr>
                        <tr class="border-t border-stone-300"><td class="py-1">{{ __('Remaining on account') }}</td><td class="py-1 text-end"><x-ui.money :amount="$line->remaining" /></td></tr>
                    </tbody>
                </table>
                <div class="mt-8 flex justify-between gap-8 text-xs text-stone-600">
                    <p class="flex-1 border-t border-stone-400 pt-1">{{ __('Received by') }}</p>
                    <p class="flex-1 border-t border-stone-400 pt-1">{{ __('Paid by') }}</p>
                </div>
            </section>
        @endforeach
        <p class="text-xs text-stone-500">{{ __('Printed on :date', ['date' => now()->format('d-m-Y g:i A')]) }}</p>
    </div>

    @if ($canReverse)
        <x-ui.modal wire:model="showReverseForm" :title="__('Reverse settlement')" :description="__('The payment is cancelled with a mirror entry; what was paid is owed to the workers again. Both stay in the history.')" max-width="md">
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
