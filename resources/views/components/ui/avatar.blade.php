@props(['initials', 'size' => 'md'])

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-800',
    'size-8 text-xs' => $size === 'sm',
    'size-9 text-sm' => $size === 'md',
]) }} aria-hidden="true" style="font-family: var(--font-numeric)">{{ $initials }}</span>
