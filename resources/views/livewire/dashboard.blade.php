<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-brand-700">{{ now()->translatedFormat('l, j F Y') }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900 sm:text-3xl">{{ $greeting }}, {{ $user->name }}</h1>
            <p class="mt-1 text-sm text-stone-500">{{ $kiln->displayName() }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($can['sale'])
                <x-ui.button icon="plus" :href="route('sales.create')" wire:navigate>{{ __('New sale') }}</x-ui.button>
            @endif
            @if ($can['production'])
                <x-ui.button variant="secondary" icon="factory" :href="route('production.index')" wire:navigate>{{ __('Record production') }}</x-ui.button>
            @endif
            @if ($can['payment'])
                <x-ui.button variant="secondary" icon="arrow-left-right" :href="route('vouchers.index')" wire:navigate>{{ __('Payments and receipts') }}</x-ui.button>
            @endif
        </div>
    </div>

    @isset($data['day'])
        <a href="{{ route('reports.day-close') }}" wire:navigate @class([
            'mb-6 flex items-center gap-3 rounded-[var(--radius-card)] px-4 py-3 text-sm ring-1 transition',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 hover:ring-emerald-300' => $data['day']['today_closed'],
            'bg-amber-50 text-amber-900 ring-amber-200 hover:ring-amber-300' => ! $data['day']['today_closed'],
        ])>
            @if ($data['day']['today_closed'])
                <x-lucide-lock class="size-5 shrink-0" aria-hidden="true" />
                <span>{{ __('Today is closed.') }}</span>
            @else
                <x-lucide-lock-open class="size-5 shrink-0" aria-hidden="true" />
                <span class="flex-1">
                    {{ __('Today is not closed yet.') }}
                    @if ($data['day']['closed_through'])
                        {!! __('Books closed up to :date.', ['date' => '<span class="tabular" dir="ltr">'.e($data['day']['closed_through']->format('d-m-Y')).'</span>']) !!}
                    @endif
                </span>
                <span class="font-medium underline">{{ __('Close the day') }}</span>
            @endif
        </a>
    @endisset

    <div class="space-y-6">
        {{-- Money --}}
        @if (isset($data['money']) || isset($data['parties']) || isset($data['wages_due']))
            <section>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">{{ __('Money') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @isset($data['money'])
                        <x-dashboard.stat :label="__('Cash and bank')" :href="route('accounts.index')" :hint="__('In today :in · out :out', ['in' => $data['money']['in']->format(), 'out' => $data['money']['out']->format()])">
                            <x-ui.money :amount="$data['money']['cash']" />
                        </x-dashboard.stat>
                    @endisset
                    @isset($data['parties'])
                        <x-dashboard.stat :label="__('Others owe us')" :href="route('reports.receivables')" :hint="__('Customers and others (لینا)')">
                            <x-ui.money :amount="$data['parties']['receivable']" />
                        </x-dashboard.stat>
                        <x-dashboard.stat :label="__('We owe others')" :href="route('parties.index')" :hint="__('Suppliers, drivers and others (دینا)')">
                            <x-ui.money :amount="$data['parties']['payable']" />
                        </x-dashboard.stat>
                    @endisset
                    @isset($data['wages_due'])
                        <x-dashboard.stat :label="__('Wages due')" :href="route('labour.index')" :hint="__('What the kiln owes its workers')">
                            <x-ui.money :amount="$data['wages_due']" />
                        </x-dashboard.stat>
                    @endisset
                </div>
            </section>
        @endif

        {{-- Today --}}
        @if (isset($data['sales']) || isset($data['production']))
            <section>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">{{ __('Today') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @isset($data['sales'])
                        <x-dashboard.stat :label="__('Sold today')" :href="route('sales.index', ['from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d')])" :hint="trans_choice(':count sale|:count sales', $data['sales']['count'], ['count' => $data['sales']['count']])">
                            <x-ui.money :amount="$data['sales']['amount']" />
                        </x-dashboard.stat>
                    @endisset
                    @isset($data['production'])
                        <x-dashboard.stat :label="__('Pathai accepted')" :href="route('production.day')" :hint="__(':made made', ['made' => $data['production']['made']->format(0)])">
                            <span class="tabular">{{ $data['production']['accepted']->format(0) }}</span>
                        </x-dashboard.stat>
                        <x-dashboard.stat :label="__('Nakaasi (unloaded)')" :href="route('production.day')" :hint="__(':loaded loaded into the kiln', ['loaded' => $data['production']['loaded']->format(0)])">
                            <span class="tabular">{{ $data['production']['unloaded']->format(0) }}</span>
                        </x-dashboard.stat>
                        <x-dashboard.stat :label="__('Bricks delivered')" :href="isset($data['sales']) ? route('sales.deliveries', ['from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d')]) : route('production.day')">
                            <span class="tabular">{{ $data['production']['delivered']->format(0) }}</span>
                        </x-dashboard.stat>
                    @endisset
                </div>
            </section>
        @endif

        {{-- Stock --}}
        @isset($data['production'])
            <section>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">{{ __('Brick stock now') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-dashboard.stat :label="__('Raw bricks')" :href="route('production.index')"><span class="tabular">{{ $data['production']['raw']->format(0) }}</span></x-dashboard.stat>
                    <x-dashboard.stat :label="__('In the kiln or awaiting Nakaasi')" :href="route('production.index')"><span class="tabular">{{ $data['production']['in_kiln']->format(0) }}</span></x-dashboard.stat>
                    <x-dashboard.stat :label="__('Ready for sale')" :href="route('production.index')"><span class="tabular">{{ $data['production']['ready']->format(0) }}</span></x-dashboard.stat>
                    <x-dashboard.stat :label="__('Free to sell')" :href="route('production.index')" :hint="isset($data['sales']) ? trans_choice(':count sale waiting for delivery|:count sales waiting for delivery', $data['sales']['open'], ['count' => $data['sales']['open']]) : null" :tone="$data['production']['free']->isNegative() ? 'bad' : null">
                        <span class="tabular">{{ $data['production']['free']->format(0) }}</span>
                    </x-dashboard.stat>
                </div>
            </section>
        @endisset

        {{-- Charts --}}
        @isset($data['finance'])
            <section class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <x-ui.card class="min-w-0">
                    <h2 class="text-base font-semibold text-stone-900">{{ __('Income and costs, last six months') }}</h2>
                    <p class="mb-4 text-xs text-stone-500">{{ __('From the profit and loss of each month. Hover or tab to a month for the amounts.') }}</p>
                    <x-charts.columns
                        :label="__('Income and costs, last six months')"
                        :categories="array_column($data['finance']['months'], 'label')"
                        :series="[
                            ['name' => __('Income'), 'values' => array_column($data['finance']['months'], 'sales')],
                            ['name' => __('Costs'), 'values' => array_column($data['finance']['months'], 'costs')],
                        ]" />
                </x-ui.card>

                <x-ui.card class="min-w-0">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-stone-900">{{ __('Costs this month') }}</h2>
                            <p class="text-xs text-stone-500">{{ __('Income this month: :amount', ['amount' => $data['finance']['month_income']->formatWithCurrency()]) }}</p>
                        </div>
                        <div class="text-end">
                            <p class="text-xs text-stone-500">{{ $data['finance']['month_profit']->isNegative() ? __('Loss') : __('Profit') }}</p>
                            <p @class(['text-lg font-semibold', 'text-emerald-700' => ! $data['finance']['month_profit']->isNegative(), 'text-red-700' => $data['finance']['month_profit']->isNegative()])><x-ui.money :amount="$data['finance']['month_profit']->abs()" /></p>
                        </div>
                    </div>
                    <x-charts.bars :label="__('Costs this month')" :rows="$data['finance']['month_costs']" />
                    <a href="{{ route('reports.profit-and-loss') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline">{{ __('Open profit and loss') }}</a>
                </x-ui.card>
            </section>
        @endisset

        @if ($data === [])
            <x-ui.card>
                <p class="text-sm text-stone-600">{{ __('Your role has no figures to show here yet. Ask the owner if you need access to more of the kiln.') }}</p>
            </x-ui.card>
        @endif
    </div>
</div>
