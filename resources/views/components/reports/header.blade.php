{{-- Report page header: back to Reports, title, description, Print and (optional) PDF; and the printed heading. --}}
@props(['title', 'description' => null, 'period' => null, 'pdf' => null])

<div class="mb-2 print:hidden">
    <a href="{{ route('reports.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-stone-500 hover:text-stone-800">
        <x-lucide-chevron-left class="size-4 rtl:rotate-180" aria-hidden="true" /> {{ __('Reports') }}
    </a>
</div>

<div class="print:hidden">
    <x-ui.page-header :title="$title" :description="$description">
        <x-slot:actions>
            <x-ui.button size="sm" variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
            @if ($pdf)
                <x-ui.button size="sm" variant="secondary" icon="file-text" :href="$pdf" target="_blank">{{ __('PDF') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
</div>

<div class="mb-4 hidden print:block">
    <p class="text-sm font-semibold text-stone-700">{{ app(\App\Support\Kiln\CurrentKiln::class)->get()->displayName() }}</p>
    <h1 class="text-xl font-semibold text-stone-900">{{ $title }}</h1>
    @if ($period)
        <p class="text-sm text-stone-600"><span class="tabular" dir="ltr">{{ $period }}</span></p>
    @endif
</div>
