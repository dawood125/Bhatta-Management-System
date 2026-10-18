@props(['active'])

@if ($active)
    <x-ui.badge tone="success" icon="circle-check">{{ __('Active') }}</x-ui.badge>
@else
    <x-ui.badge icon="archive">{{ __('Archived') }}</x-ui.badge>
@endif
