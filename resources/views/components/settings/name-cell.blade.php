{{-- Name in the current language, with the other language underneath. --}}
@props(['record'])

@php
    $isUrdu = app()->getLocale() === 'ur';
    $secondary = $isUrdu ? $record->name : $record->name_ur;
@endphp

<div class="min-w-0">
    <p class="truncate font-medium text-stone-900">{{ $record->displayName() }}</p>
    @if (filled($secondary) && $secondary !== $record->displayName())
        {{-- <bdi> keeps the other language's direction without changing the alignment of the line. --}}
        <p class="truncate text-xs text-stone-500"><bdi lang="{{ $isUrdu ? 'en' : 'ur' }}" dir="{{ $isUrdu ? 'ltr' : 'rtl' }}">{{ $secondary }}</bdi></p>
    @endif
</div>
