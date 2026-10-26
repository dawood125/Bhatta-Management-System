@php
    /** @var \App\Models\Kiln $kiln */
    /** @var \App\Models\Sale $sale */
    $rtl = app()->getLocale() === 'ur';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        {{-- Lateef (Urdu) runs smaller than Latin type at the same size, so Urdu pages use a larger size. --}}
        body { font-size: {{ $rtl ? '13pt' : '10pt' }}; color: #1c1917; }
        .num { font-family: dejavusans; direction: ltr; font-size: {{ $rtl ? '10pt' : 'inherit' }}; }
        .muted { color: #78716c; }
        .small { font-size: {{ $rtl ? '10.5pt' : '8.5pt' }}; }
        h1 { font-size: 17pt; margin: 0; color: #9a3412; }
        table { border-collapse: collapse; width: 100%; }
        .lines th { background: #f5f5f4; color: #57534e; font-size: {{ $rtl ? '10.5pt' : '8.5pt' }}; text-transform: uppercase; padding: 6px 5px; border-bottom: 1px solid #d6d3d1; }
        .lines td { padding: 6px 5px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        .totals td { padding: 4px 5px; }
        .totals .grand td { border-top: 1.5px solid #1c1917; font-weight: bold; font-size: 11pt; }
        .box { border: 1px solid #e7e5e4; padding: 8px 10px; }
        .stamp { color: #b91c1c; border: 2px solid #b91c1c; padding: 4px 10px; font-weight: bold; font-size: 12pt; }
    </style>
</head>
<body>
    {{-- Kiln and invoice number --}}
    <table>
        <tr>
            <td style="width: 60%; vertical-align: top; text-align: {{ $start }};">
                @if ($logo)
                    <img src="{{ $logo }}" style="height: 52px; margin-bottom: 4px;" alt="">
                @endif
                <div style="font-size: 14pt; font-weight: bold;">{{ $kiln->displayName() }}</div>
                @if ($kiln->displayAddress())
                    <div class="muted">{{ $kiln->displayAddress() }}</div>
                @endif
                <div class="muted">
                    @if ($kiln->phone)<bdo dir="ltr" class="num">{{ $kiln->phone }}</bdo>@endif
                    @if ($kiln->phone && $kiln->email) · @endif
                    @if ($kiln->email)<bdo dir="ltr" class="num">{{ $kiln->email }}</bdo>@endif
                </div>
            </td>
            <td style="width: 40%; vertical-align: top; text-align: {{ $end }};">
                <h1>{{ __('Invoice') }}</h1>
                <div style="font-size: 12pt; font-weight: bold;"><bdo dir="ltr" class="num">{{ $sale->number }}</bdo></div>
                <div>{{ __('Date') }}: <bdo dir="ltr" class="num">{{ $sale->sale_date->format('d-m-Y') }}</bdo></div>
                <div style="margin-top: 6px;">
                    @if ($sale->isCancelled())
                        <span class="stamp">{{ __('Cancelled') }}</span>
                    @elseif ($payment['status'])
                        <span class="small" style="font-weight: bold;">{{ $payment['status']->label() }}</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Customer --}}
    <table style="margin-top: 14px;">
        <tr>
            <td class="box" style="width: 60%; vertical-align: top;">
                <div class="muted small">{{ __('Bill to') }}</div>
                <div style="font-size: 11pt; font-weight: bold;">{{ $sale->customer->displayName() }}</div>
                @if ($sale->customer->phone)
                    <div><bdo dir="ltr" class="num">{{ $sale->customer->phone }}</bdo></div>
                @endif
                @if ($sale->customer->address)
                    <div>{{ $sale->customer->address }}</div>
                @endif
            </td>
            <td style="width: 4%;"></td>
            <td class="box" style="width: 36%; vertical-align: top;">
                <div class="muted small">{{ __('Status') }}</div>
                <div>{{ $sale->status->label() }}</div>
                @if ($sale->agent)
                    <div class="muted small" style="margin-top: 4px;">{{ __('Sales agent') }}</div>
                    <div>{{ $sale->agent->displayName() }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Lines --}}
    <table class="lines" style="margin-top: 16px;">
        <thead>
            <tr>
                <th style="text-align: {{ $start }};">{{ __('Grade') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Quantity') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Rate') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Discount') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Amount') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Delivered') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->lines as $line)
                @php($decimals = $line->product->unit->decimals)
                <tr>
                    <td style="text-align: {{ $start }};">{{ $line->product->displayName() }}</td>
                    <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $line->quantity->format($decimals) }}</bdo> {{ $line->product->unit->symbol ?? $line->product->unit->displayName() }}</td>
                    <td style="text-align: {{ $end }};">
                        <bdo dir="ltr" class="num">{{ $line->rate->format() }}</bdo>
                        <div class="muted small">{{ $line->rate_per === 1000 ? __('per 1,000 bricks') : __('per :unit', ['unit' => $line->product->unit->symbol ?? $line->product->unit->displayName()]) }}</div>
                    </td>
                    <td style="text-align: {{ $end }};" class="num">{{ $line->discount->isZero() ? '—' : $line->discount->format() }}</td>
                    <td style="text-align: {{ $end }};" class="num">{{ $line->amount->format() }}</td>
                    <td style="text-align: {{ $end }};" class="num">{{ \App\Support\Quantity::fromMilli($delivered[$line->id] ?? 0)->format($decimals) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                <table class="totals">
                    <tr><td>{{ __('Bricks') }}</td><td style="text-align: {{ $end }};" class="num">{{ $sale->total->format() }}</td></tr>
                    @if ($carriage->isPositive())
                        <tr><td>{{ __('Carriage') }}</td><td style="text-align: {{ $end }};" class="num">{{ $carriage->format() }}</td></tr>
                    @endif
                    @if ($credit->isPositive())
                        <tr><td>{{ __('Bricks not delivered') }}</td><td style="text-align: {{ $end }};" class="num">−{{ $credit->format() }}</td></tr>
                    @endif
                    <tr class="grand"><td>{{ __('Total') }}</td><td style="text-align: {{ $end }};" >{{ __('Rs') }} <bdo dir="ltr" class="num">{{ $payment['due']->format() }}</bdo></td></tr>
                    @unless ($sale->isCancelled())
                        <tr><td>{{ __('Paid') }}</td><td style="text-align: {{ $end }};" class="num">{{ $payment['paid']->format() }}</td></tr>
                        <tr><td style="font-weight: bold;">{{ __('Still to pay') }}</td><td style="text-align: {{ $end }}; font-weight: bold;" >{{ __('Rs') }} <bdo dir="ltr" class="num">{{ $payment['outstanding']->format() }}</bdo></td></tr>
                    @endunless
                </table>
                <div class="muted small" style="margin-top: 4px; text-align: {{ $end }};">
                    {{ __('Customer balance now') }}:
                    {{ __('Rs') }} <bdo dir="ltr" class="num">{{ $balance->abs()->format() }}</bdo>
                    {{ $balance->isPositive() ? __('receivable') : ($balance->isNegative() ? __('in advance') : '') }}
                </div>
            </td>
        </tr>
    </table>

    {{-- Deliveries --}}
    @if ($dispatches->isNotEmpty())
        <div style="margin-top: 16px; font-weight: bold;">{{ __('Deliveries') }}</div>
        <table class="lines" style="margin-top: 4px;">
            <thead>
                <tr>
                    <th style="text-align: {{ $start }};">{{ __('Number') }}</th>
                    <th style="text-align: {{ $start }};">{{ __('Date') }}</th>
                    <th style="text-align: {{ $start }};">{{ __('Bricks') }}</th>
                    <th style="text-align: {{ $start }};">{{ __('Vehicle and driver') }}</th>
                    <th style="text-align: {{ $end }};">{{ __('Carriage') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dispatches as $dispatch)
                    <tr>
                        <td class="num" style="text-align: {{ $start }};">{{ $dispatch->number }}</td>
                        <td class="num" style="text-align: {{ $start }};">{{ $dispatch->dispatch_date->format('d-m-Y') }}</td>
                        <td style="text-align: {{ $start }};">
                            @foreach ($dispatch->lines as $dl)
                                @php($line = $sale->lines->firstWhere('id', $dl->sale_line_id))
                                <div>{{ $line?->product->displayName() }}: <bdo dir="ltr" class="num">{{ $dl->quantity->format($line?->product->unit->decimals ?? 0) }}</bdo></div>
                            @endforeach
                        </td>
                        <td style="text-align: {{ $start }};">
                            {{ collect([$dispatch->vehicle, $dispatch->driver?->displayName()])->filter()->implode(' · ') ?: '—' }}
                        </td>
                        <td class="num" style="text-align: {{ $end }};">{{ $dispatch->carriage->isZero() ? '—' : $dispatch->carriage->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($sale->notes)
        <div style="margin-top: 12px;"><span class="muted">{{ __('Note') }}:</span> {{ $sale->notes }}</div>
    @endif

    @if ($kiln->invoice_note)
        <div class="box small" style="margin-top: 12px;">{{ $kiln->invoice_note }}</div>
    @endif

    <table style="margin-top: 44px;">
        <tr>
            <td style="width: 38%; border-top: 1px solid #78716c; padding-top: 3px; text-align: {{ $start }};">{{ __('Customer signature') }}</td>
            <td style="width: 24%;"></td>
            <td style="width: 38%; border-top: 1px solid #78716c; padding-top: 3px; text-align: {{ $end }};">{{ __('For :kiln', ['kiln' => $kiln->displayName()]) }}</td>
        </tr>
    </table>

    <div class="muted small" style="margin-top: 18px; text-align: center;">
        {{ __('Printed on') }} <bdo dir="ltr" class="num">{{ now()->format('d-m-Y H:i') }}</bdo>
    </div>
</body>
</html>
