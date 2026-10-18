@props(['icon' => 'inbox', 'title', 'description' => null])

<div {{ $attributes->class('flex flex-col items-center justify-center px-6 py-14 text-center') }}>
    <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-stone-100 text-stone-400">
        <x-dynamic-component :component="'lucide-'.$icon" class="size-6" aria-hidden="true" />
    </div>
    <h3 class="text-sm font-semibold text-stone-900">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-stone-500">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
