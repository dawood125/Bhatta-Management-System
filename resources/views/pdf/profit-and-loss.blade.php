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
                <div style="font-size: 14pt; font-weight: bold; color: #9a3412;">{{ __('Profit and loss') }}</div>
                <div class="muted"><bdo dir="ltr" class="num">{{ $from->format('d-m-Y') }} – {{ $to->format('d-m-Y') }}</bdo></div>
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 14px;">
        <tr class="head"><td style="text-align: {{ $start }};">{{ __('Income') }}</td><td></td></tr>
        @foreach ($report['income'] as $row)
            <tr><td class="indent" style="text-align: {{ $start }};">{{ $row['account']->displayName() }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $row['amount']->format() }}</bdo></td></tr>
        @endforeach
        <tr class="total"><td style="text-align: {{ $start }};">{{ __('Total income') }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $report['total_income']->format() }}</bdo></td></tr>

        @foreach ($report['groups'] as $group)
            <tr class="head"><td style="text-align: {{ $start }};">{{ $group['label'] }}</td><td></td></tr>
            @foreach ($group['rows'] as $row)
                <tr><td class="indent" style="text-align: {{ $start }};">{{ $row['account']->displayName() }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $row['amount']->format() }}</bdo></td></tr>
            @endforeach
            <tr><td style="text-align: {{ $start }};" class="muted">{{ __('Total :group', ['group' => mb_strtolower($group['label'])]) }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $group['total']->format() }}</bdo></td></tr>
        @endforeach

        <tr class="total"><td style="text-align: {{ $start }};">{{ __('Total costs') }}</td><td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $report['total_expenses']->format() }}</bdo></td></tr>
        <tr class="total">
            <td style="text-align: {{ $start }};">{{ $report['net']->isNegative() ? __('Loss') : __('Profit') }}</td>
            <td style="text-align: {{ $end }}; color: {{ $report['net']->isNegative() ? '#b91c1c' : '#047857' }};">{{ __('Rs') }} <bdo dir="ltr" class="num">{{ $report['net']->abs()->format() }}</bdo></td>
        </tr>
    </table>

    <div class="muted" style="margin-top: 16px; text-align: center; font-size: 8pt;">
        {{ __('Printed on') }} <bdo dir="ltr" class="num">{{ now()->format('d-m-Y H:i') }}</bdo>
    </div>
</body>
</html>
