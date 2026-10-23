<div>
    <x-reports.header :title="__('Balance sheet')" :description="__('What the kiln owns, what it owes, and what it is worth to the owner on a date.')"
        :period="$asOf !== '' ? \Illuminate\Support\Carbon::parse($asOf)->format('d-m-Y') : null" :pdf="$pdfUrl" />

    <x-ui.card class="mb-4 print:hidden">
        <div class="w-44">
            <label for="bs-date" class="mb-1 block text-xs font-medium text-stone-500">{{ __('As of date') }}</label>
            <x-ui.input id="bs-date" type="date" wire:model.live="asOf" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
        </div>
    </x-ui.card>

    @php($report = $this->report)
    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date')" />
        </x-ui.card>
    @else
        <div @class([
            'mb-4 flex items-center gap-3 rounded-[var(--radius-card)] px-4 py-3 text-sm font-medium ring-1',
            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $report['balanced'],
            'bg-red-50 text-red-800 ring-red-200' => ! $report['balanced'],
        ]) role="status">
            @if ($report['balanced'])
                <x-lucide-scale class="size-5 shrink-0" aria-hidden="true" /> {{ __('Balanced: what the kiln owns equals what it owes plus what it is worth.') }}
            @else
                <x-lucide-triangle-alert class="size-5 shrink-0" aria-hidden="true" /> {{ __('Not balanced. Please report this immediately.') }}
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2" wire:loading.class="opacity-60" wire:target="asOf">
            @foreach ([
                [__('What the kiln owns'), $report['assets'], __('Total assets'), $report['total_assets']],
                [__('What the kiln owes'), $report['liabilities'], __('Total liabilities'), $report['total_liabilities']],
                [__('Owner\'s equity'), $report['equity'], __('Total equity'), $report['total_equity']],
            ] as [$heading, $rows, $totalLabel, $total])
                <x-ui.card :padding="false" :class="$loop->index === 2 ? 'lg:col-start-2' : ''">
                    <h2 class="border-b border-stone-100 px-4 py-3 text-base font-semibold text-stone-900">{{ $heading }}</h2>
                    <dl class="divide-y divide-stone-100 text-sm">
                        @forelse ($rows as $row)
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <dt class="text-stone-700">
                                    @if (isset($row['treasury_id']))
                                        <a href="{{ route('accounts.book', $row['treasury_id']) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $row['label'] }}</a>
                                    @else
                                        {{ $row['label'] }}
                                    @endif
                                </dt>
                                <dd><x-ui.money :amount="$row['amount']" :currency="false" /></dd>
                            </div>
                        @empty
                            <div class="px-4 py-2.5 text-stone-400">{{ __('None') }}</div>
                        @endforelse
                        <div class="flex items-center justify-between gap-3 bg-stone-50/80 px-4 py-2.5 font-semibold">
                            <dt>{{ $totalLabel }}</dt>
                            <dd><x-ui.money :amount="$total" /></dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endforeach

            <x-ui.card class="lg:col-start-2">
                <div class="flex items-center justify-between gap-3 text-sm font-semibold text-stone-900">
                    <span>{{ __('Liabilities and equity') }}</span>
                    <x-ui.money :amount="$report['total_liabilities']->add($report['total_equity'])" />
                </div>
            </x-ui.card>
        </div>

        @if ($bricks = $this->bricks)
            <x-ui.card class="mt-4">
                <h2 class="text-sm font-semibold text-stone-900">{{ __('Bricks in stock (not valued)') }}</h2>
                <p class="mb-3 text-xs text-stone-500">{{ __('Their cost is already counted in the profit as materials, fuel and labour were used.') }}</p>
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    @foreach ([[__('Raw bricks'), $bricks['raw']], [__('In the kiln'), $bricks['in_firing']], [__('Awaiting Nakaasi'), $bricks['awaiting']], [__('Ready for sale'), $bricks['saleable']]] as [$label, $quantity])
                        <div>
                            <dt class="text-xs text-stone-500">{{ $label }}</dt>
                            <dd class="tabular font-semibold text-stone-900">{{ $quantity->format(0) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        @endif
    @endif
</div>
