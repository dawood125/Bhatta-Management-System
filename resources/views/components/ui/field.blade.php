@props([
    'label' => null,
    'for' => null,       // id of the control; also used as the error key unless "error" is given
    'error' => null,     // validation key, e.g. "form.username"
    'hint' => null,
    'required' => false,
])

@php
    $errorKey = $error ?? $for;
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    @if ($label)
        <label for="{{ $for }}" class="block text-sm font-medium text-stone-700">
            {{ $label }}
            @if ($required)
                <span class="text-red-600" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($errorKey && $errors->has($errorKey))
        <p id="{{ $for }}-error" class="flex items-start gap-1.5 text-sm text-red-600">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ $errors->first($errorKey) }}</span>
        </p>
    @elseif ($hint)
        <p class="text-xs text-stone-500">{{ $hint }}</p>
    @endif
</div>
