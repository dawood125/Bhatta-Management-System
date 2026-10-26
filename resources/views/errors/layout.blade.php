{{--
    Error page in English and Urdu together: on an error the user's language may not be known
    (no session yet, or the error came before it was read). Built to render even if the compiled
    assets are missing: it then falls back to the small inline styles.
--}}
@php
    try {
        $assets = app(\Illuminate\Foundation\Vite::class)('resources/css/app.css');
    } catch (\Throwable) {
        $assets = null;
    }
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }} · {{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    {{ $assets }}
    <style>
        body { margin: 0; min-height: 100vh; background: #faf8f6; color: #292524; font-family: ui-sans-serif, system-ui, sans-serif; }
        .error-card { max-width: 32rem; }
    </style>
</head>
<body class="bg-canvas">
<main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="error-card w-full rounded-[var(--radius-card)] bg-surface p-6 shadow-card ring-1 ring-stone-200/80 sm:p-8">
        <div class="flex items-center gap-3">
            <x-brand.logo class="size-9" />
            <span class="text-sm font-semibold text-stone-900">{{ config('app.name') }}</span>
            <span class="ms-auto rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium tabular text-stone-600">{{ $code }}</span>
        </div>

        <div class="mt-6">
            <h1 class="text-xl font-semibold text-stone-900">{{ $title }}</h1>
            <p class="mt-2 text-sm text-stone-600">{{ $message }}</p>
        </div>

        <div class="mt-6 border-t border-stone-100 pt-6 text-end" lang="ur" dir="rtl">
            <h2 class="text-xl font-semibold text-stone-900">{{ $titleUr }}</h2>
            <p class="mt-2 text-sm text-stone-600">{{ $messageUr }}</p>
        </div>

        <div class="mt-8 flex flex-wrap gap-2">
            <a href="{{ $href ?? url('/dashboard') }}" class="inline-flex items-center gap-2 rounded-[var(--radius-control)] bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                {{ $action ?? 'Go to the dashboard' }}
                <span lang="ur">· {{ $actionUr ?? 'ڈیش بورڈ پر جائیں' }}</span>
            </a>
        </div>
    </div>
</main>
</body>
</html>
