@props(['padding' => true])

<div {{ $attributes->class([
    'rounded-[var(--radius-card)] bg-surface shadow-card ring-1 ring-stone-200/80',
    'p-5 sm:p-6' => $padding,
]) }}>
    {{ $slot }}
</div>
