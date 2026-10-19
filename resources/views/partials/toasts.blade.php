{{-- Toast notifications. Livewire: $this->dispatch('toast', type: 'success', message: '...'); after a redirect: session()->flash('toast', [...]). --}}
<div
    x-data="toastCenter()"
    x-on:toast.window="add($event.detail)"
    @if (session('toast')) x-init="add(@js(session('toast')))" @endif
    class="pointer-events-none fixed inset-x-0 top-16 z-[60] print:hidden flex flex-col items-center gap-2 p-4 sm:items-end"
    aria-live="polite"
>
    <template x-for="item in items" :key="item.id">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl bg-white p-4 shadow-pop ring-1 ring-stone-200"
            role="status"
        >
            <span x-show="item.type === 'success'" class="text-emerald-600"><x-lucide-circle-check class="size-5" aria-hidden="true" /></span>
            <span x-show="item.type === 'error'" class="text-red-600"><x-lucide-circle-x class="size-5" aria-hidden="true" /></span>
            <span x-show="item.type === 'warning'" class="text-amber-600"><x-lucide-triangle-alert class="size-5" aria-hidden="true" /></span>
            <span x-show="item.type === 'info'" class="text-sky-600"><x-lucide-info class="size-5" aria-hidden="true" /></span>
            <p class="min-w-0 flex-1 text-sm font-medium text-stone-800" x-text="item.message"></p>
            <button type="button" x-on:click="remove(item.id)" class="-m-1 rounded p-1 text-stone-400 hover:text-stone-600" aria-label="{{ __('Close') }}">
                <x-lucide-x class="size-4" aria-hidden="true" />
            </button>
        </div>
    </template>
</div>
