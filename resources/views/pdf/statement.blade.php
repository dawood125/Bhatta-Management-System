@php
    use App\Support\JournalPresenter;
    $rtl = app()->getLocale() === 'ur';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $badge = fn ($amount) => $amount->isPositive() ? __('Receivable') : ($amount->isNegative() ? __('Payable') : __('Settled'));
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: {{ $rtl ? '12.5pt' : '9.5pt' }}; color: #1c1917; }
        .num { font-family: dejavusans; font-size: {{ $rtl ? '9.5pt' : 'inherit' }}; }
        .muted { color: #78716c; }
        table { border-collapse: collapse; width: 100%; }
        .rows th { background: #f5f5f4; color: #57534e; font-size: {{ $rtl ? '10pt' : '8pt' }}; text-transform: uppercase; padding: 5px 4px; border-bottom: 1px solid #d6d3d1; }
        .rows td { padding: 5px 4px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        .rows .sum td { font-weight: bold; border-top: 1.5px solid #1c1917; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="text-align: {{ $start }}; vertical-align: top;">
                <div style="font-size: 13pt; font-weight: bold;">{{ $kiln->displayName() }}</div>
                @if ($kiln->displayAddress())<div class="muted">{{ $kiln->displayAddress() }}</div>@endif
            </td>
            <td style="text-align: {{ $end }}; vertical-align: top;">
                <div style="font-size: 14pt; font-weight: bold; color: #9a3412;">{{ __('Statement') }}</div>
                <div>{{ $party->displayName() }}</div>
                <div class="muted"><bdo dir="ltr" class="num">{{ $from->format('d-m-Y') }} – {{ $to->format('d-m-Y') }}</bdo></div>
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 14px;">
        <thead>
            <tr>
                <th style="text-align: {{ $start }};">{{ __('Date') }}</th>
                <th style="text-align: {{ $start }};">{{ __('Number') }}</th>
                <th style="text-align: {{ $start }};">{{ __('Description') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Debit (banam)') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Credit (jama)') }}</th>
                <th style="text-align: {{ $end }};">{{ __('Balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="5" style="text-align: {{ $start }};">{{ __('Balance brought forward') }}</td>
                <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $statement['opening']->abs()->format() }}</bdo> <span class="muted">{{ $badge($statement['opening']) }}</span></td>
            </tr>
            @foreach ($statement['rows'] as $row)
                <tr>
                    <td style="text-align: {{ $start }};"><bdo dir="ltr" class="num">{{ $row['line']->entry->entry_date->format('d-m-Y') }}</bdo></td>
                    <td style="text-align: {{ $start }};"><bdo dir="ltr" class="num">{{ $row['line']->entry->number }}</bdo></td>
                    <td style="text-align: {{ $start }};">
                        {{ JournalPresenter::describe($row['line']->entry) }}
                        @if (filled($row['line']->memo))<div class="muted">{{ $row['line']->memo }}</div>@endif
                    </td>
                    <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $row['debit']->isZero() ? '' : $row['debit']->format() }}</bdo></td>
                    <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $row['credit']->isZero() ? '' : $row['credit']->format() }}</bdo></td>
                    <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $row['balance']->abs()->format() }}</bdo> <span class="muted">{{ $badge($row['balance']) }}</span></td>
                </tr>
            @endforeach
            <tr class="sum">
                <td colspan="3" style="text-align: {{ $start }};">{{ __('Closing balance') }}</td>
                <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $statement['total_debit']->format() }}</bdo></td>
                <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $statement['total_credit']->format() }}</bdo></td>
                <td style="text-align: {{ $end }};"><bdo dir="ltr" class="num">{{ $statement['closing']->abs()->format() }}</bdo> {{ $badge($statement['closing']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="muted" style="margin-top: 16px; text-align: center; font-size: 8pt;">
        {{ __('Printed on') }} <bdo dir="ltr" class="num">{{ now()->format('d-m-Y H:i') }}</bdo>
    </div>
</body>
</html>
