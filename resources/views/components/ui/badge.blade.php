@props([
    'tone' => 'neutral', // neutral | brand | success | warning | danger | info
    'icon' => null,
])

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap',
    match ($tone) {
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-200',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'danger' => 'bg-red-50 text-red-700 ring-red-200',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-200',
        default => 'bg-stone-100 text-stone-700 ring-stone-200',
    },
]) }}>
    @if ($icon)
        <x-dynamic-component :component="'lucide-'.$icon" class="size-3.5 shrink-0" aria-hidden="true" />
    @endif
    {{ $slot }}
</span>
