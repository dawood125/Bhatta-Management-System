@php use App\Reports\ReceivablesAgeing; @endphp
<div>
    <x-reports.header :title="__('Who owes us')" :description="__('Every customer who owes the kiln today, split by how old their unpaid sales are. Payments count towards the oldest sales first.')"
        :period="now()->format('d-m-Y')" />

    @php($report = $this->report)
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach (ReceivablesAgeing::BUCKETS as $key => $limit)
            <x-ui.card class="py-4!">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ ReceivablesAgeing::bucketLabel($key) }}</p>
                <p @class(['mt-1 text-lg font-semibold', 'text-red-700' => $key === 'over_90' && $report['totals']['buckets'][$key]->isPositive(), 'text-stone-900' => ! ($key === 'over_90' && $report['totals']['buckets'][$key]->isPositive())])><x-ui.money :amount="$report['totals']['buckets'][$key]" /></p>
            </x-ui.card>
        @endforeach
        <x-ui.card class="py-4!">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total owed') }}</p>
            <p class="mt-1 text-lg font-semibold text-stone-900"><x-ui.money :amount="$report['totals']['balance']" /></p>
        </x-ui.card>
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th class="min-w-44">{{ __('Customer') }}</x-ui.th>
            @foreach (ReceivablesAgeing::BUCKETS as $key => $limit)
                <x-ui.th align="end">{{ ReceivablesAgeing::bucketLabel($key) }}</x-ui.th>
            @endforeach
            <x-ui.th align="end">{{ __('Other') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Total owed') }}</x-ui.th>
        </x-slot:head>
        @forelse ($report['rows'] as $row)
            <tr wire:key="owes-{{ $row['party']->id }}">
                <x-ui.td>
                    <a href="{{ route('parties.show', $row['party']) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $row['party']->displayName() }}</a>
                    @if ($row['party']->phone)
                        <p class="text-xs text-stone-500" dir="ltr">{{ $row['party']->phone }}</p>
                    @endif
                </x-ui.td>
                @foreach (ReceivablesAgeing::BUCKETS as $key => $limit)
                    <x-ui.td align="end"><x-ui.money :amount="$row['buckets'][$key]" :currency="false" :muted="$row['buckets'][$key]->isZero()" /></x-ui.td>
                @endforeach
                <x-ui.td align="end"><x-ui.money :amount="$row['other']" :currency="false" :muted="$row['other']->isZero()" /></x-ui.td>
                <x-ui.td align="end" class="font-semibold"><x-ui.money :amount="$row['balance']" :currency="false" /></x-ui.td>
            </tr>
        @empty
            <tr><td colspan="7"><x-ui.empty-state icon="hourglass" :title="__('Nobody owes the kiln anything')" /></td></tr>
        @endforelse
    </x-ui.table>
    <p class="mt-3 text-xs text-stone-500">{{ __('"Other" is the rest of a customer\'s balance: an opening balance or money refunded to them, less an advance not used yet.') }}</p>
</div>
