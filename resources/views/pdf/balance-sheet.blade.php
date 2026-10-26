@php
    $rtl = app()->getLocale() === 'ur';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    @include('pdf.report-styles')
</head>
<body>
    <table>
        <tr>
            <td style="text-align: {{ $start }}; vertical-align: top;">
                <div style="font-size: 13pt; font-weight: bold;">{{ $kiln->displayName() }}</div>
                @if ($kiln->displayAddress())<div class="muted">{{ $kiln->displayAddress() }}</div>@endif
            </td>
            <td style="text-align: {{ $end }}; vertical-align: top;">
                <div style="font-size: 14pt; font-weight: bold; color: #9a3412;">{{ __('Balance sheet') }}</div>
                <div class="muted">{{ __('As of date') }}: <bdo dir="ltr" class="num">{{ $date->format('d-m-Y') }}</bdo></div>
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 14px;">
        @foreach ([
            [__('What the kiln owns'), $report['assets'], __('Total assets'), $report['total_assets']],
            [__('What the kiln owes'), $report['liabilities'], __('Total liabilities'), $report['total_liabilities']],
            [__('Owner\'s equity'), $report['equity'], __('Total equity'), $report['total_equity']],
        ] as [$heading, $rows, $totalLabel, $total])
            <tr class="head"><td style="text-align: {{ $start }};">{{ $heading }}</td><td></td></tr>
            @foreach ($rows as $row)
                <tr><td class="indent" style="text-align: {{ $start }};">{{ $row['label'] }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $row['amount']->format() }}</bdo></td></tr>
            @endforeach
            <tr class="total"><td style="text-align: {{ $start }};">{{ $totalLabel }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $total->format() }}</bdo></td></tr>
        @endforeach
        <tr class="total">
            <td style="text-align: {{ $start }};">{{ __('Liabilities and equity') }}</td>
            <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $report['total_liabilities']->add($report['total_equity'])->format() }}</bdo></td>
        </tr>
    </table>

    <p style="margin-top: 10px;" class="muted">
        {{ $report['balanced'] ? __('Balanced: what the kiln owns equals what it owes plus what it is worth.') : __('Not balanced. Please report this immediately.') }}
        {{ __('Bricks in stock are not valued: their cost is already counted in the profit.') }}
    </p>

    <div class="muted" style="margin-top: 16px; text-align: center; font-size: 8pt;">
        {{ __('Printed on') }} <bdo dir="ltr" class="num">{{ now()->format('d-m-Y H:i') }}</bdo>
    </div>
</body>
</html>
