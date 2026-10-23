@php use App\Enums\PayBasis; @endphp
<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Labour')" :description="__('Wages earned for work, salaries and contracts, and the labour cost by category.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
                @if ($canRecord)
                    <x-ui.button icon="pickaxe" wire:click="create">{{ __('Record work') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </div>

    <x-labour.tabs active="work" />

    {{-- Printed heading --}}
    <div class="mb-4 hidden print:block">
        <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
        <h1 class="text-xl font-semibold text-stone-900">{{ __('Labour cost report') }}</h1>
        @if ($range)
            <p class="text-sm text-stone-600">{{ __('Period') }}: <span class="tabular" dir="ltr">{{ $range[0]->format('d-m-Y') }} – {{ $range[1]->format('d-m-Y') }}</span></p>
        @endif
    </div>

    <x-ui.card class="mb-4 print:hidden">
        <x-date-range id="work-range" />
    </x-ui.card>

    @if ($range === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date range')" :description="__('The start date must be on or before the end date.')" />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Labour cost') }}</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900"><x-ui.money :amount="$this->totals['total']" /></p>
                <p class="text-xs text-stone-500">{{ __('Reversed wages are not counted.') }}</p>
            </x-ui.card>
            <x-ui.card class="py-4!">
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('By category') }}</p>
                @if ($this->totals['by_category'] === [])
                    <p class="text-sm text-stone-400">{{ __('No wages earned in this period.') }}</p>
                @else
                    <ul class="grid gap-x-8 gap-y-1.5 sm:grid-cols-2">
                        @foreach ($this->totals['by_category'] as $row)
                            <li class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate text-stone-700">
                                    {{ $row['name'] }}
                                    @if ($row['quantity']->isPositive() && $row['basis'] !== PayBasis::PerMonth)
                                        <span class="text-xs text-stone-500">· <bdi>{{ $row['quantity']->format($row['basis']->decimals()) }}</bdi> {{ mb_strtolower($row['basis']->quantityLabel()) }}</span>
                                    @endif
                                </span>
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
                    <label for="work-search" class="sr-only">{{ __('Search') }}</label>
                    <x-ui.input id="work-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search worker, number or details')" />
                </div>
                <div class="w-full sm:w-52 print:hidden">
                    <label for="work-category" class="sr-only">{{ __('Category') }}</label>
                    <x-ui.select id="work-category" wire:model.live="category" :placeholder="__('All categories')" :options="$this->categoryOptions" />
                </div>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Number') }}</x-ui.th>
                <x-ui.th class="min-w-56">{{ __('Worker') }}</x-ui.th>
                <x-ui.th class="min-w-48">{{ __('Work') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Wages') }}</x-ui.th>
            </x-slot:head>

            @forelse ($this->entries as $work)
                <tr wire:key="work-{{ $work->id }}" @class(['align-top transition-colors hover:bg-stone-50/70', 'bg-red-50/40' => $work->entry->isReversed()]) wire:loading.class="opacity-60" wire:target="category,search,from,to,setRange,gotoPage,nextPage,previousPage">
                    <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $work->work_date->format('d-m-Y') }}</span></x-ui.td>
                    <x-ui.td class="whitespace-nowrap"><span class="tabular font-medium text-stone-800" dir="ltr">{{ $work->entry->number }}</span></x-ui.td>
                    <x-ui.td>
                        <a href="{{ route('labour.workers.show', $work->worker) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $work->worker->displayName() }}</a>
                        <p class="text-xs text-stone-500">{{ $work->category->displayName() }}</p>
                    </x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            @if ($work->quantity && $work->rate)
                                <span class="text-stone-700"><bdi>{{ $work->quantity->format($work->basis->decimals()) }}</bdi> {{ mb_strtolower($work->basis->quantityLabel()) }} × <x-ui.money :amount="$work->rate" /></span>
                            @elseif ($work->basis === PayBasis::LumpSum)
                                <span class="text-stone-700">{{ __('Contract') }}</span>
                            @endif
                            @if ($work->productionEntry)
                                <x-ui.badge tone="brand"><span dir="ltr">{{ $work->productionEntry->number }}</span></x-ui.badge>
                            @endif
                            @if ($work->entry->isReversed())
                                <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </div>
                        @if ($work->salary_month)
                            <p class="mt-0.5 text-xs text-stone-500">{{ __('Salary for :month', ['month' => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $work->salary_month.'-01')->translatedFormat('F Y')]) }}</p>
                        @endif
                        @if (filled($work->description))
                            <p class="mt-0.5 text-stone-600"><bdi>{{ $work->description }}</bdi></p>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end" @class(['font-medium', 'line-through decoration-stone-400' => $work->entry->isReversed()])><x-ui.money :amount="$work->amount" /></x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-ui.empty-state icon="pickaxe" :title="__('No wages in this period')" :description="$search !== '' || $category !== '' ? __('Try a different search or filter.') : __('Wages appear here when work is recorded, a production entry names a worker, or salaries are posted.')" />
                    </td>
                </tr>
            @endforelse

            @if ($this->entries->hasPages())
                <x-slot:footer>{{ $this->entries->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif

    @if ($canRecord)
        @php($basis = $this->formWorker?->category->basis)
        <x-ui.modal wire:model="showForm" :title="__('Record work')" :description="__('Wages at the rate of that day. They are paid with the next settlement.')" max-width="lg">
            <form id="work-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2" x-on:work-saved.window="$nextTick(() => document.getElementById('work-worker')?.focus())">
                <x-ui.field :label="__('Worker')" for="work-worker" error="form.worker_id" required :hint="__('Salaried workers are paid from Attendance and salaries.')" class="sm:col-span-2">
                    <x-ui.select id="work-worker" wire:model.live="form.worker_id" :placeholder="__('Choose a worker')" :options="$this->workerOptions" />
                </x-ui.field>

                <x-ui.field :label="__('Date')" for="work-form-date" error="form.work_date" required>
                    <x-ui.input id="work-form-date" type="date" wire:model.live="form.work_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>

                @if ($basis === PayBasis::LumpSum)
                    <x-ui.field :label="__('Amount')" for="work-form-amount" error="form.amount" required>
                        <x-ui.input id="work-form-amount" wire:model="form.amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" autocomplete="off" />
                    </x-ui.field>
                @elseif ($basis !== null)
                    <x-ui.field :label="$basis->quantityLabel()" for="work-form-quantity" error="form.quantity" required :hint="$basis === PayBasis::PerDay ? __('Half days as .5') : null">
                        <x-ui.input id="work-form-quantity" wire:model.live.debounce.400ms="form.quantity" inputmode="decimal" dir="ltr" autocomplete="off" />
                    </x-ui.field>
                    <div class="rounded-[var(--radius-control)] bg-stone-50 px-3 py-2 text-sm ring-1 ring-stone-200 sm:col-span-2" aria-live="polite">
                        @if ($this->formRate)
                            <p class="text-stone-600">{{ __('Rate on that day') }}: <x-ui.money :amount="$this->formRate->rate" class="font-medium text-stone-800" /> {{ $basis->rateUnit() }}</p>
                            @if ($this->formAmount)
                                <p class="mt-0.5 font-medium text-stone-900">{{ __('Wages') }}: <x-ui.money :amount="$this->formAmount" /></p>
                            @endif
                        @else
                            <p class="text-amber-800">{{ __('No rate on that day. Set one in Categories and rates first.') }}</p>
                        @endif
                    </div>
                @endif

                <x-ui.field :label="__('Details')" for="work-form-description" error="form.description" :hint="__('Optional')" class="sm:col-span-2">
                    <x-ui.input id="work-form-description" wire:model="form.description" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="secondary" icon="copy-plus" wire:click="save(true)" loading="save">{{ __('Save and add another') }}</x-ui.button>
                <x-ui.button type="submit" form="work-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
