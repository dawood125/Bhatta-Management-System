@php($rtl = app()->getLocale() === 'ur')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background: #f5f5f4; font-family: Arial, sans-serif; color: #1c1917;">
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px; text-align: {{ $rtl ? 'right' : 'left' }};">
        <p style="margin: 0 0 4px; color: #78716c; font-size: 13px;">{{ $kiln->displayName() }}</p>
        <h1 style="margin: 0 0 16px; font-size: 20px;">{{ __('New sale :number', ['number' => $sale->number]) }}</h1>

        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr><td style="padding: 4px 0; color: #78716c;">{{ __('Customer') }}</td><td style="padding: 4px 0; font-weight: bold;">{{ $sale->customer->displayName() }}</td></tr>
            <tr><td style="padding: 4px 0; color: #78716c;">{{ __('Date') }}</td><td style="padding: 4px 0;" dir="ltr">{{ $sale->sale_date->format('d-m-Y') }}</td></tr>
            @foreach ($sale->lines as $line)
                <tr>
                    <td style="padding: 4px 0; color: #78716c;">{{ $line->product->displayName() }}</td>
                    <td style="padding: 4px 0;"><span dir="ltr">{{ $line->quantity->format($line->product->unit->decimals) }}</span> {{ $line->product->unit->symbol }}</td>
                </tr>
            @endforeach
            <tr><td style="padding: 8px 0 4px; color: #78716c;">{{ __('Total') }}</td><td style="padding: 8px 0 4px; font-weight: bold;" dir="ltr">{{ $sale->total->formatWithCurrency() }}</td></tr>
        </table>

        <p style="margin: 20px 0 0; font-size: 13px; color: #57534e;">{{ __('The invoice is attached as a PDF.') }}</p>
    </div>
</body>
</html>
