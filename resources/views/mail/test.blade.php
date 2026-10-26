@php($rtl = app()->getLocale() === 'ur')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background: #f5f5f4; font-family: Arial, sans-serif; color: #1c1917;">
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px; text-align: {{ $rtl ? 'right' : 'left' }};">
        <p style="margin: 0 0 4px; color: #78716c; font-size: 13px;">{{ config('app.name') }}</p>
        <h1 style="margin: 0 0 16px; font-size: 20px;">{{ __('The email settings work.') }}</h1>
        <p style="margin: 0; font-size: 14px;">{{ __('This test email was sent by :name from Settings → Email. Invoice emails and backup alerts will go out the same way.', ['name' => $sentBy->name]) }}</p>
    </div>
</body>
</html>
