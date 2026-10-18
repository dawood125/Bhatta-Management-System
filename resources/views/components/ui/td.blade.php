@props(['align' => 'start', 'numeric' => false])

<td {{ $attributes->class([
    'px-4 py-3 align-middle text-stone-700',
    'text-start' => $align === 'start',
    'text-end' => $align === 'end',
    'tabular whitespace-nowrap' => $numeric,
]) }}>
    {{ $slot }}
</td>
