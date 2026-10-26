<div>
    <x-ui.page-header :title="__('Reports')" :description="__('Every report in one place. All of them come from the same books, so their totals always agree.')" />

    <div class="space-y-8">
        @foreach ($groups as $group)
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500">{{ $group['label'] }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($group['items'] as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate class="group flex items-start gap-3 rounded-[var(--radius-card)] bg-white p-4 shadow-xs ring-1 ring-stone-200 transition hover:ring-brand-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                <x-dynamic-component :component="'lucide-'.$item['icon']" class="size-5" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-medium text-stone-900 group-hover:text-brand-700">{{ $item['title'] }}</span>
                                <span class="block text-sm text-stone-500">{{ $item['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>
