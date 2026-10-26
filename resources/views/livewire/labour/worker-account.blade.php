@php use App\Enums\PayBasis; use App\Support\JournalPresenter; @endphp
<div>
    <div class="mb-2 print:hidden">
        <a href="{{ route('labour.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
            <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Workers') }}
        </a>
    </div>

    {{-- Heading (also the printed header) --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="hidden text-sm font-semibold text-stone-700 print:block">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ __('Account') }}: {{ $worker->displayName() }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-500">
                <x-ui.badge tone="brand">{{ $worker->category->displayName() }}</x-ui.badge>
                @if (! $worker->is_active)
                    <x-ui.badge>{{ __('Archived') }}</x-ui.badge>
                @endif
                @if ($worker->phone)
                    <span class="tabular" dir="ltr">{{ $worker->phone }}</span>
                @endif
                @if ($basis->hasRate())
                    <span>
                        @if ($this->currentRate)
                            {{ __('Rate') }}: <x-ui.money :amount="$this->currentRate->rate" class="font-medium text-stone-700" /> {{ $basis->rateUnit() }}
                            <span class="text-stone-400">({{ $this->currentRate->worker_id ? __('own rate') : __('category rate') }})</span>
                        @else
                            <span class="text-amber-700">{{ __('No rate set yet') }}</span>
                        @endif
                    </span>
                @else
                    <span>{{ $basis->label() }}</span>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Current balance') }}</p>
            <x-worker-balance :amount="$this->currentBalance" size="lg" />
            <x-ui.button size="sm" variant="secondary" icon="printer" x-on:click="window.print()" class="print:hidden">{{ __('Print') }}</x-ui.button>
        </div>
    </div>

    @if ($canRecord || $canPay)
        <div class="mb-4 flex flex-wrap gap-2 print:hidden" role="group" aria-label="{{ __('Record for this worker') }}">
            @if ($canRecord && $basis !== PayBasis::PerMonth)
                <x-ui.button icon="pickaxe" wire:click="openWork">{{ $basis === PayBasis::LumpSum ? __('Record contract payment due') : __('Record work') }}</x-ui.button>
            @endif
            @if ($canPay)
                <x-ui.button variant="secondary" icon="hand-coins" wire:click="openMoney('give')">{{ __('Give advance') }}</x-ui.button>
                <x-ui.button variant="secondary" icon="banknote-arrow-down" wire:click="openMoney('receive')">{{ __('Advance paid back') }}</x-ui.button>
            @endif
            @if ($canRecord)
                <x-ui.button variant="secondary" icon="circle-minus" wire:click="openDeduction">{{ __('Deduction') }}</x-ui.button>
            @endif
        </div>
        @if ($basis === PayBasis::PerMonth)
            <p class="mb-4 text-sm text-stone-500 print:hidden">{{ __('The salary is posted each month from') }} <a href="{{ route('labour.attendance') }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ __('Attendance and salaries') }}</a>.</p>
        @elseif ($worker->category->activity)
            <p class="mb-4 text-sm text-stone-500 print:hidden">{{ __('Work is also earned automatically when this worker is named on a :work production entry.', ['work' => $worker->category->activity->entryKind()->label()]) }}</p>
        @endif
    @endif

    <x-ui.card class="mb-4 print:hidden">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid grid-cols-2 gap-3 sm:w-96">
                <div>
                    <label for="account-from" class="mb-1 block text-xs font-medium text-stone-500">{{ __('From date') }}</label>
                    <x-ui.input id="account-from" type="date" wire:model.live="from" dir="ltr" />
                </div>
                <div>
                    <label for="account-to" class="mb-1 block text-xs font-medium text-stone-500">{{ __('To date') }}</label>
                    <x-ui.input id="account-to" type="date" wire:model.live="to" dir="ltr" />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button size="sm" variant="secondary" wire:click="setRange('this_week')">{{ __('This week') }}</x-ui.button>
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
                <x-ui.th align="end">{{ __('Earned') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Taken') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
                @if ($canReverse)
                    <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                @endif
            </x-slot:head>

            <tr class="bg-stone-50/60">
                <x-ui.td colspan="5" class="font-medium text-stone-600">{{ __('Balance brought forward') }}</x-ui.td>
                <x-ui.td align="end"><x-worker-balance :amount="$statement['brought_forward']" /></x-ui.td>
                @if ($canReverse)
                    <x-ui.td class="print:hidden" />
                @endif
            </tr>

            @forelse ($statement['rows'] as $row)
                @php($entry = $row['line']->entry)
                @php($work = $this->workEntries[$entry->id] ?? null)
                <tr wire:key="wline-{{ $row['line']->id }}" @class(['align-top', 'bg-red-50/40' => $entry->isReversed() || $entry->type === \App\Enums\JournalType::Reversal])>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $entry->entry_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular text-stone-600" dir="ltr">{{ $entry->number }}</span></x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="JournalPresenter::typeTone($entry->type)">{{ $entry->type->label() }}</x-ui.badge>
                            @if ($entry->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        @if ($work)
                            <p class="mt-1 text-stone-700">
                                @if ($work->quantity && $work->rate)
                                    <bdi>{{ $work->quantity->format($work->basis->decimals()) }}</bdi> {{ mb_strtolower($work->basis->quantityLabel()) }}
                                    × <x-ui.money :amount="$work->rate" /> {{ $work->basis->rateUnit() }}
                                @endif
                                @if ($work->salary_month)
                                    · {{ __('Salary for :month', ['month' => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $work->salary_month.'-01')->translatedFormat('F Y')]) }}
                                @endif
                                @if ($work->productionEntry)
                                    · <span class="tabular" dir="ltr">{{ $work->productionEntry->number }}</span>
                                @endif
                            </p>
                            @if (filled($work->description))
                                <p class="mt-0.5 text-xs text-stone-500"><bdi>{{ $work->description }}</bdi></p>
                            @endif
                        @else
                            @if (filled($row['line']->memo))
                                <p class="mt-1 text-stone-700"><bdi>{{ $row['line']->memo }}</bdi></p>
                            @elseif (filled($entry->memo))
                                <p class="mt-1 text-stone-700"><bdi>{{ $entry->memo }}</bdi></p>
                            @endif
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end">@if ($row['earned']->isPositive())<x-ui.money :amount="$row['earned']" :currency="false" />@endif</x-ui.td>
                    <x-ui.td align="end">@if ($row['taken']->isPositive())<x-ui.money :amount="$row['taken']" :currency="false" />@endif</x-ui.td>
                    <x-ui.td align="end"><x-worker-balance :amount="$row['balance']" /></x-ui.td>
                    @if ($canReverse)
                        <x-ui.td align="end" class="print:hidden">
                            @if ($entry->canBeReversed() && ! $work?->production_entry_id)
                                <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $entry->id }})" :aria-label="__('Reverse :number', ['number' => $entry->number])" :title="__('Reverse')" />
                            @endif
                        </x-ui.td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canReverse ? 7 : 6 }}"><x-ui.empty-state icon="book-open" :title="__('Nothing recorded in this period')" /></td></tr>
            @endforelse

            <tr class="bg-stone-50/80 font-semibold">
                <x-ui.td colspan="3">{{ __('Closing balance') }}</x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['total_earned']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$statement['total_taken']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-worker-balance :amount="$statement['closing']" /></x-ui.td>
                @if ($canReverse)
                    <x-ui.td class="print:hidden" />
                @endif
            </tr>
        </x-ui.table>
        <x-ui.statement-pager :statement="$statement" />

        <p class="mt-3 text-xs text-stone-500">{{ __('Earned: wages, salary and contract amounts. Taken: advances, deductions and wages paid.') }}</p>
        <p class="mt-1 hidden text-xs text-stone-500 print:block">{{ __('Printed on :date', ['date' => now()->format('d-m-Y g:i A')]) }}</p>
    @endif

    <x-ui.modal wire:model="showDialog" :title="match ($dialog) {
        'work' => $basis === PayBasis::LumpSum ? __('Record contract payment due') : __('Record work'),
        'money' => ($form['direction'] ?? '') === 'receive' ? __('Advance paid back') : __('Give advance'),
        'deduction' => __('Deduction'),
        'reverse' => __('Reverse entry'),
        default => '',
    }" :description="match ($dialog) {
        'work' => $basis === PayBasis::LumpSum ? __('The agreed amount the kiln now owes :name. Pay it with a settlement.', ['name' => $worker->displayName()]) : __('Wages at the rate of that day. They are paid with the next settlement.'),
        'money' => ($form['direction'] ?? '') === 'receive' ? __(':name returns money taken as an advance.', ['name' => $worker->displayName()]) : __('Money given now and taken off the next settlement.'),
        'deduction' => __('Taken off the wages, e.g. a fine or damage. It is not paid out from cash.'),
        'reverse' => $this->reversing ? __('A mirror entry will cancel :number. Both stay in the history.', ['number' => $this->reversing->number]) : null,
        default => null,
    }" max-width="md">
        <form id="labour-dialog-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            @error('form.worker_id')
                <p class="flex items-start gap-1.5 rounded-[var(--radius-control)] bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 sm:col-span-2" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
            @enderror

            @if ($dialog === 'work')
                <x-ui.field :label="__('Date')" for="work-date" error="form.work_date" required>
                    <x-ui.input id="work-date" type="date" wire:model.live="form.work_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                @if ($basis === PayBasis::LumpSum)
                    <x-ui.field :label="__('Amount')" for="work-amount" error="form.amount" required>
                        <x-ui.input id="work-amount" wire:model="form.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('Details')" for="work-description" error="form.description" :hint="__('For example: which firing round or month it is for.')" class="sm:col-span-2">
                        <x-ui.input id="work-description" wire:model="form.description" />
                    </x-ui.field>
                @else
                    <x-ui.field :label="$basis->quantityLabel()" for="work-quantity" error="form.quantity" required :hint="$basis === PayBasis::PerDay ? __('Half days as .5') : null">
                        <x-ui.input id="work-quantity" wire:model.live.debounce.400ms="form.quantity" inputmode="decimal" dir="ltr" autocomplete="off" />
                    </x-ui.field>
                    <div class="rounded-[var(--radius-control)] bg-stone-50 px-3 py-2 text-sm ring-1 ring-stone-200 sm:col-span-2" aria-live="polite">
                        @if ($this->workRate)
                            <p class="text-stone-600">{{ __('Rate on that day') }}: <x-ui.money :amount="$this->workRate->rate" class="font-medium text-stone-800" /> {{ $basis->rateUnit() }}</p>
                            @if ($this->workAmount)
                                <p class="mt-0.5 font-medium text-stone-900">{{ __('Wages') }}: <x-ui.money :amount="$this->workAmount" /></p>
                            @endif
                        @else
                            <p class="text-amber-800">{{ __('No rate on that day. Set one in Categories and rates first.') }}</p>
                        @endif
                    </div>
                    <x-ui.field :label="__('Details')" for="work-description" error="form.description" :hint="__('Optional')" class="sm:col-span-2">
                        <x-ui.input id="work-description" wire:model="form.description" />
                    </x-ui.field>
                @endif
            @elseif ($dialog === 'money')
                <x-ui.field :label="__('Amount')" for="money-amount" error="form.amount" required>
                    <x-ui.input id="money-amount" wire:model="form.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Date')" for="money-date" error="form.entry_date" required>
                    <x-ui.input id="money-date" type="date" wire:model="form.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="($form['direction'] ?? '') === 'receive' ? __('Received into') : __('Paid from')" for="money-account" error="form.account_id" required class="sm:col-span-2">
                    <x-ui.select id="money-account" wire:model.live="form.account_id" :placeholder="__('Choose an account')" :options="$this->accountOptions" />
                    @if ($this->accountBalance !== null)
                        <p class="text-xs text-stone-500">{{ __('Balance now') }}: <x-ui.money :amount="$this->accountBalance" class="font-medium text-stone-700" /></p>
                    @endif
                </x-ui.field>
                <x-ui.field :label="__('Note')" for="money-memo" error="form.memo" :hint="__('Optional')" class="sm:col-span-2">
                    <x-ui.input id="money-memo" wire:model="form.memo" />
                </x-ui.field>
            @elseif ($dialog === 'deduction')
                <x-ui.field :label="__('Amount')" for="deduction-amount" error="form.amount" required>
                    <x-ui.input id="deduction-amount" wire:model="form.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Date')" for="deduction-date" error="form.entry_date" required>
                    <x-ui.input id="deduction-date" type="date" wire:model="form.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Reason')" for="deduction-memo" error="form.memo" required class="sm:col-span-2">
                    <x-ui.input id="deduction-memo" wire:model="form.memo" />
                </x-ui.field>
            @elseif ($dialog === 'reverse')
                <x-ui.field :label="__('Reason')" for="reverse-reason" error="form.reason" required :hint="__('For example: wrong amount, entered twice.')" class="sm:col-span-2">
                    <x-ui.input id="reverse-reason" wire:model="form.reason" />
                </x-ui.field>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            @if ($dialog === 'reverse')
                <x-ui.button type="submit" form="labour-dialog-form" variant="danger" icon="undo-2" loading="save">{{ __('Reverse') }}</x-ui.button>
            @else
                <x-ui.button type="submit" form="labour-dialog-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>
