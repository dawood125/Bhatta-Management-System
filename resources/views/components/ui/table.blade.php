{{-- Responsive table shell: scrolls horizontally on small screens instead of breaking the page. --}}
<div {{ $attributes->class('relative overflow-hidden rounded-[var(--radius-card)] bg-surface shadow-card ring-1 ring-stone-200/80') }}>
    @isset($toolbar)
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            {{ $toolbar }}
        </div>
    @endisset

    {{-- "relative" keeps absolutely positioned children (e.g. sr-only labels) inside the scroll box,
         otherwise they escape it and make the whole page scroll sideways on phones. --}}
    <div class="relative overflow-x-auto">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            @isset($head)
                <thead class="bg-stone-50/80">
                    <tr>{{ $head }}</tr>
                </thead>
            @endisset
            <tbody class="divide-y divide-stone-100 bg-white">
                {{ $slot }}
            </tbody>
        </table>
    </div>

    @isset($footer)
        <div class="border-t border-stone-200 px-4 py-3">
            {{ $footer }}
        </div>
    @endisset
</div>
