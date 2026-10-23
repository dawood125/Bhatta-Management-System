{{-- Buttons that open the production dialogs (rendered by <livewire:production.entry-forms />). --}}
@can(\App\Enums\Permission::ProductionRecord->value)
    <div role="group" aria-label="{{ __('Record production') }}" {{ $attributes->class('flex flex-wrap gap-2') }}>
        @foreach ([\App\Enums\ProductionEntryKind::Pathai, \App\Enums\ProductionEntryKind::Loading, \App\Enums\ProductionEntryKind::Firing, \App\Enums\ProductionEntryKind::Nakaasi] as $kind)
            <x-ui.button :variant="$kind === \App\Enums\ProductionEntryKind::Pathai ? 'primary' : 'secondary'" :icon="$kind->icon()" x-on:click="$dispatch('open-production-form', { kind: '{{ $kind->value }}' })">{{ $kind->label() }}</x-ui.button>
        @endforeach
        @can(\App\Enums\Permission::ProductionAdjust->value)
            <x-ui.button variant="ghost" icon="clipboard-pen" x-on:click="$dispatch('open-production-form', { kind: 'adjustment' })">{{ __('Adjust stock') }}</x-ui.button>
        @endcan
    </div>
@endcan
