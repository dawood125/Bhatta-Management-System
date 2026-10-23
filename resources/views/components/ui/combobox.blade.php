@props([
    'source',              // id of a <script type="application/json"> holding [{value, label, hint, search}]
    'placeholder' => null,
])

{{--
    A searchable select for long lists (e.g. 1,000+ customers). Bind it like an input:
    <x-ui.combobox id="line-0-party" source="party-options" wire:model.live="form.lines.0.party_id" />
    The option list lives once on the page (in the JSON script), so many comboboxes stay light.
--}}

@php
    $name = $attributes->wire('model')->value() ?: $attributes->get('name');
    $invalid = $name && $errors->has($name);
    $id = $attributes->get('id', $name);
@endphp

<div
    x-data="combobox(@js($source))"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    x-on:click.outside="close()"
    x-on:keydown.escape="if (open) { $event.stopPropagation(); close(); $refs.button.focus(); }"
    class="relative"
>
    <button
        type="button"
        id="{{ $id }}"
        x-ref="button"
        x-on:click="toggle()"
        x-on:keydown.arrow-down.prevent="open = true"
        x-on:keydown="typeAhead($event)"
        aria-haspopup="listbox"
        :aria-expanded="open.toString()"
        @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class([
            'flex h-10 w-full items-center gap-2 rounded-[var(--radius-control)] border bg-white ps-3 pe-9 text-start text-sm shadow-xs',
            'transition focus:outline-none focus:ring-3',
            'border-stone-300 focus:border-brand-500 focus:ring-brand-500/20' => ! $invalid,
            'border-red-400 focus:border-red-500 focus:ring-red-500/20' => $invalid,
        ])
    >
        <span class="min-w-0 flex-1 truncate" :class="selected ? 'text-stone-900' : 'text-stone-400'">
            <bdi x-text="selected ? selected.label : @js($placeholder ?? __('Choose…'))">{{ $placeholder ?? __('Choose…') }}</bdi>
        </span>
    </button>
    <x-lucide-chevrons-up-down class="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute inset-x-0 top-full z-40 mt-1 min-w-64 overflow-hidden rounded-[var(--radius-control)] bg-white shadow-pop ring-1 ring-stone-200"
    >
        <div class="border-b border-stone-100 p-2">
            <input
                x-ref="search"
                x-model="query"
                type="search"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $id }}-listbox"
                :aria-activedescendant="filtered[active] ? '{{ $id }}-option-' + active : null"
                aria-label="{{ __('Search') }}"
                placeholder="{{ __('Type to search…') }}"
                x-on:input="active = 0"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="choose(filtered[active])"
                x-on:keydown.tab="close()"
                class="block h-9 w-full rounded-md border border-stone-300 px-2.5 text-sm placeholder:text-stone-400 focus:border-brand-500 focus:outline-none focus:ring-3 focus:ring-brand-500/20"
            >
        </div>
        <ul id="{{ $id }}-listbox" x-ref="list" role="listbox" class="max-h-64 overflow-y-auto py-1">
            <template x-for="(option, index) in filtered" :key="option.value">
                <li
                    :id="'{{ $id }}-option-' + index"
                    role="option"
                    :aria-selected="(String(option.value) === String(value)).toString()"
                    x-on:click="choose(option)"
                    x-on:mousemove="active = index"
                    class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm"
                    :class="index === active ? 'bg-brand-50 text-brand-900' : 'text-stone-800'"
                >
                    <bdi class="min-w-0 truncate" x-text="option.label"></bdi>
                    <span x-show="option.hint" class="shrink-0 text-xs text-stone-500" x-text="option.hint"></span>
                </li>
            </template>
            <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-stone-500">{{ __('No matches.') }}</li>
            <li x-show="more" class="px-3 py-1.5 text-xs text-stone-400">{{ __('Keep typing to narrow the list.') }}</li>
        </ul>
    </div>
</div>
