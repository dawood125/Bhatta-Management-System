@props([
    'sortable' => null,  // column key passed to the component's sortBy()
    'sortField' => null,
    'sortDirection' => 'asc',
    'align' => 'start',  // start | end
])

<th scope="col" {{ $attributes->class([
    'px-4 py-3 text-xs font-semibold uppercase tracking-wide text-stone-500 whitespace-nowrap',
    'text-start' => $align === 'start',
    'text-end' => $align === 'end',
]) }}
    @if ($sortable) aria-sort="{{ $sortField === $sortable ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif
>
    @if ($sortable)
        <button type="button" wire:click="sortBy('{{ $sortable }}')" class="group inline-flex items-center gap-1 uppercase hover:text-stone-800">
            {{ $slot }}
            @if ($sortField === $sortable)
                <x-dynamic-component :component="$sortDirection === 'asc' ? 'lucide-arrow-up' : 'lucide-arrow-down'" class="size-3.5 text-brand-600" aria-hidden="true" />
            @else
                <x-lucide-arrow-up-down class="size-3.5 opacity-0 transition group-hover:opacity-60" aria-hidden="true" />
            @endif
        </button>
    @else
        {{ $slot }}
    @endif
</th>
