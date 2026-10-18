@props(['label' => null, 'description' => null])

@php
    $id = $attributes->get('id', $attributes->wire('model')->value() ?: $attributes->get('name'));
@endphp

<label for="{{ $id }}" class="flex cursor-pointer items-start gap-3">
    <input
        type="checkbox"
        id="{{ $id }}"
        {{ $attributes->class('mt-0.5 size-4 shrink-0 cursor-pointer rounded border-stone-300 text-brand-600 accent-brand-600 focus:ring-brand-500') }}
    />
    @if ($label || $description)
        <span class="min-w-0">
            @if ($label)
                <span class="block text-sm font-medium text-stone-800">{{ $label }}</span>
            @endif
            @if ($description)
                <span class="block text-xs text-stone-500">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>
