<div>
    <x-ui.page-header :title="__('Trial balance')" :description="__('The balance of every account on a date. Total debits always equal total credits.')">
        <x-slot:actions>
            <div class="w-44">
                <label for="tb-date" class="mb-1 block text-xs font-medium text-stone-500">{{ __('As of date') }}</label>
                <x-ui.input id="tb-date" type="date" wire:model.live="asOf" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @php($report = $this->report)

    @if ($report === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid date')" />
        </x-ui.card>
    @else
        @php($balanced = $report['total_debit']->equals($report['total_credit']))
        <div @class([
            'mb-4 flex items-center gap-3 rounded-[var(--radius-card)] px-4 py-3 text-sm font-medium ring-1',
            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $balanced,
            'bg-red-50 text-red-800 ring-red-200' => ! $balanced,
        ]) role="status">
            @if ($balanced)
                <x-lucide-scale class="size-5 shrink-0" aria-hidden="true" /> {{ __('Balanced: total debits equal total credits.') }}
            @else
                <x-lucide-triangle-alert class="size-5 shrink-0" aria-hidden="true" /> {{ __('Not balanced. Please report this immediately.') }}
            @endif
        </div>

        <x-ui.table wire:loading.class="opacity-60" wire:target="asOf">
            <x-slot:head>
                <x-ui.th>{{ __('Code') }}</x-ui.th>
                <x-ui.th>{{ __('Account') }}</x-ui.th>
                <x-ui.th>{{ __('Type') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Debit') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Credit') }}</x-ui.th>
            </x-slot:head>

            @forelse ($report['rows'] as $row)
                <tr wire:key="tb-{{ $row['account']->id }}">
                    <x-ui.td><span class="tabular text-stone-500" dir="ltr">{{ $row['account']->code }}</span></x-ui.td>
                    <x-ui.td>
                        @if (isset($this->treasuryLinks[$row['account']->id]))
                            <a href="{{ route('accounts.book', $this->treasuryLinks[$row['account']->id]) }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700 hover:underline">{{ $row['account']->displayName() }}</a>
                        @else
                            <span class="font-medium text-stone-900">{{ $row['account']->displayName() }}</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="text-stone-500">{{ $row['account']->type->label() }}</x-ui.td>
                    <x-ui.td align="end">@if ($row['debit']->isPositive())<x-ui.money :amount="$row['debit']" :currency="false" />@endif</x-ui.td>
                    <x-ui.td align="end">@if ($row['credit']->isPositive())<x-ui.money :amount="$row['credit']" :currency="false" />@endif</x-ui.td>
                </tr>
            @empty
                <tr><td colspan="5"><x-ui.empty-state icon="scale" :title="__('No balances yet')" :description="__('Add cash and bank accounts with their opening balances to start.')" /></td></tr>
            @endforelse

            <tr class="bg-stone-50/80 font-semibold">
                <x-ui.td colspan="3">{{ __('Total') }}</x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$report['total_debit']" :currency="false" /></x-ui.td>
                <x-ui.td align="end"><x-ui.money :amount="$report['total_credit']" :currency="false" /></x-ui.td>
            </tr>
        </x-ui.table>
    @endif
</div>
