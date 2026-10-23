{{-- Sections of the Labour area. "active" is passed in (the route is not known during Livewire updates). --}}
@props(['active'])

<nav class="-mx-4 mb-4 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0 print:hidden [&::-webkit-scrollbar]:hidden" aria-label="{{ __('Labour') }}">
    <div class="inline-flex min-w-max gap-1 rounded-lg bg-stone-200/60 p-1">
        @foreach ([
            'workers' => ['labour.index', __('Workers')],
            'work' => ['labour.work', __('Work and wages')],
            'attendance' => ['labour.attendance', __('Attendance and salaries')],
            'settlements' => ['labour.settlements', __('Settlements')],
            'rates' => ['labour.rates', __('Categories and rates')],
        ] as $key => [$route, $label])
            <a href="{{ route($route) }}" wire:navigate @class([
                'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition',
                'bg-white text-stone-900 shadow-xs' => $active === $key,
                'text-stone-600 hover:text-stone-900' => $active !== $key,
            ]) @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </div>
</nav>
