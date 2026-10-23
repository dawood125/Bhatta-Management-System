<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Expenses')" :description="__('Kiln expenses paid from cash or bank, by category.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
                @if ($canRecord)
                    <x-ui.button icon="plus" wire:click="create">{{ __('Add expense') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </div>

    {{-- Printed heading --}}
    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Expense report') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="expenses-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total expenses') }}</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900"><x-ui.money :amount="$this->totals['total']" /></p>
                <p class="text-xs text-stone-500">{{ __('Entries: :count', ['count' => number_format($this->totals['count'])]) }} · {{ __('Reversed expenses are not counted.') }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('By category') }}</p>
                @if ($this->totals['by_category'] === [])
                    <p class="text-sm text-stone-400">{{ __('Nothing spent in this period.') }}</p>
                @else
                    <ul class="grid gap-x-8 gap-y-1.5 sm:grid-cols-2">
                        @foreach ($this->totals['by_category'] as $row)
                            <li class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate text-stone-700">{{ $row['name'] }}</span>
                                <x-ui.money :amount="$row['amount']" class="font-medium text-stone-900" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <x-ui.table>
            <x-slot:toolbar>
                <div class="w-full sm:max-w-xs print:hidden">
                    <label for="expenses-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="expenses-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search number or details')" />
                </div>
                <div class="grid w-full grid-cols-2 gap-3 sm:w-auto print:hidden">
                    <div class="sm:w-48">
                        <label for="expenses-category" class="sr-only">{{ __('Category') }}</label>
                        <x-ui.select id="expenses-category" wire:model.live="category" :placeholder="__('All categories')" :options="$this->categoryOptions" />
                    </div>
                    <div class="sm:w-48">
                        <label for="expenses-account" class="sr-only">{{ __('Paid from') }}</label>
                        <x-ui.select id="expenses-account" wire:model.live="account" :placeholder="__('All accounts')" :options="$this->accountOptions" />
                    </div>
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-56">{{ __('Category') }}</x-ui.th>
                <x-ui.th>{{ __('Paid from') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
                <x-ui.th align="end" class="print:hidden"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
            </x-slot:head>

            @forelse ($this->expenses as $expense)
                <tr wire:key="expense-{{ $expense->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $expense->isReversed()]) wire:loading.class="opacity-60" wire:target="category,account,search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $expense->expense_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular font-medium text-stone-800" dir="ltr">{{ $expense->entry->number }}</span></x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                            <span class="font-medium text-stone-900">{{ $expense->category->displayName() }}</span>
                            @if ($expense->subCategory)
                                <x-lucide-chevron-right class="size-3.5 text-stone-400 rtl:rotate-180" aria-hidden="true" />
                                <span class="text-stone-700">{{ $expense->subCategory->displayName() }}</span>
                            @endif
                            @if ($expense->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        @if (filled($expense->description))
                            <p class="mt-0.5 text-stone-600"><bdi>{{ $expense->description }}</bdi></p>
                        @endif
                        <p class="mt-0.5 text-xs text-stone-400 print:hidden">{{ __('By :name', ['name' => $expense->creator?->name ?? __('System')]) }}</p>
                    </x-ui.td>
                    <x-ui.td class="whitespace-nowrap">{{ $expense->treasuryAccount->displayName() }}</x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $expense->isReversed()])><x-ui.money :amount="$expense->amount" /></x-ui.td>
                    <x-ui.td align="end" class="print:hidden">
                        @if ($canReverse && $expense->entry->canBeReversed())
                            <x-ui.button size="sm" variant="ghost" icon="undo-2" wire:click="confirmReverse({{ $expense->id }})" :aria-label="__('Reverse :number', ['number' => $expense->entry->number])" :title="__('Reverse')" />
                        @endif
                    </x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state icon="receipt-text" :title="__('No expenses in this period')" :description="$search !== '' || $category !== '' || $account !== '' ? __('Try a different search or filter.') : __('Record fuel, repairs, bills and other kiln expenses here.')">
                            @if ($canRecord && $search === '' && $category === '' && $account === '')
                                <x-ui.button icon="plus" variant="secondary" wire:click="create">{{ __('Add expense') }}</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse

            @if ($this->expenses->hasPages())
                <x-slot:footer>{{ $this->expenses->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif

    @if ($canRecord)
        <x-ui.modal wire:model="showForm" :title="__('Add expense')" max-width="lg">
            <form id="expense-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2" x-on:expense-saved.window="$nextTick(() => document.getElementById('expense-amount')?.focus())">
                <x-ui.field :label="__('Amount')" for="expense-amount" error="form.amount" required>
                    <x-ui.input id="expense-amount" wire:model="form.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Date')" for="expense-date" error="form.entry_date" required>
                    <x-ui.input id="expense-date" type="date" wire:model="form.entry_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                <x-ui.field :label="__('Category')" for="expense-category" error="form.expense_category_id" required :hint="__('Add categories in Kiln settings.')">
                    <x-ui.select id="expense-category" wire:model.live="form.expense_category_id" :placeholder="__('Choose a category')" :options="$this->formCategoryOptions" />
                </x-ui.field>
                <x-ui.field :label="__('Sub-category')" for="expense-sub" error="form.expense_sub_category_id" :hint="__('Optional')">
                    <x-ui.select id="expense-sub" wire:model="form.expense_sub_category_id" :placeholder="$this->subCategoryOptions === [] ? __('None') : __('Choose a sub-category')" :options="$this->subCategoryOptions" :disabled="$this->subCategoryOptions === []" />
                </x-ui.field>

                <x-ui.field :label="__('Paid from')" for="expense-account" error="form.account_id" required class="sm:col-span-2">
                    <x-ui.select id="expense-account" wire:model.live="form.account_id" :placeholder="__('Choose an account')" :options="$this->formAccountOptions" />
                    @if ($this->formAccountBalance !== null)
                        <p class="text-xs text-stone-500">{{ __('Balance now') }}: <x-ui.money :amount="$this->formAccountBalance" class="font-medium text-stone-700" /></p>
                    @endif
                </x-ui.field>

                <x-ui.field :label="__('Details')" for="expense-description" error="form.description" :hint="__('Optional. For example: who was paid, bill number.')" class="sm:col-span-2">
                    <x-ui.input id="expense-description" wire:model="form.description" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="secondary" icon="copy-plus" wire:click="save(true)" loading="save">{{ __('Save and add another') }}</x-ui.button>
                <x-ui.button type="submit" form="expense-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($canReverse)
        <x-ui.modal wire:model="showReverseForm" :title="__('Reverse expense')" :description="$this->reversing ? __('A mirror entry will cancel :number. Both stay in the history.', ['number' => $this->reversing->entry->number]) : null" max-width="md">
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
