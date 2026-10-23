@php use App\Enums\StageKind; use App\Models\Unit; @endphp
@php($bricksForSale = \App\Support\Quantity::fromMilli((int) collect($this->products)->filter(fn ($p) => $p->unit->code === Unit::BRICK)->sum(fn ($p) => ($this->levels['saleable'][$p->id] ?? \App\Support\Quantity::zero())->milli)))
<div>
    <x-ui.page-header :title="__('Production')" :description="__('Bricks at every stage, from Pathai to ready for sale.')" />

    <x-production.tabs active="stock" />

    <x-production.actions class="mb-5" />

    {{-- The stages in order, left to right (right to left in Urdu) --}}
    <ol class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([StageKind::Raw, StageKind::InFiring, StageKind::FiredAwaitingUnloading, StageKind::Saleable] as $index => $stage)
            @php($quantity = $stage === StageKind::Saleable ? $bricksForSale : $this->levels[$stage->value])
            <li>
                <x-ui.card class="relative h-full py-4!">
                    <p class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-stone-500">
                        <span class="inline-flex size-5 items-center justify-center rounded-full bg-stone-100 text-[11px] font-semibold text-stone-600" aria-hidden="true">{{ $index + 1 }}</span>
                        {{ $this->stages->get($stage->value)?->displayName() ?? $stage->defaultName() }}
                    </p>
                    <p @class(['mt-2 text-2xl font-semibold tabular', 'text-stone-400' => $quantity->isZero(), 'text-stone-900' => ! $quantity->isZero()]) dir="ltr">{{ $quantity->format(0) }}</p>
                    <p class="text-xs text-stone-500">{{ __('bricks') }} · {{ $stage->meaning() }}</p>
                </x-ui.card>
            </li>
        @endforeach
    </ol>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th class="min-w-48">{{ __('Ready for sale, by grade') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th align="end">{{ __('In stock') }}</x-ui.th>
        </x-slot:head>

        @forelse ($this->products as $product)
            @php($quantity = $this->levels['saleable'][$product->id] ?? \App\Support\Quantity::zero())
            <tr wire:key="grade-{{ $product->id }}" @class(['opacity-60' => ! $product->is_active])>
                <x-ui.td><x-settings.name-cell :record="$product" /></x-ui.td>
                <x-ui.td><x-ui.badge :tone="$product->kind === \App\Enums\ProductKind::FiredGrade ? 'brand' : 'neutral'">{{ $product->kind->label() }}</x-ui.badge></x-ui.td>
                <x-ui.td align="end" class="whitespace-nowrap">
                    <span @class(['tabular font-semibold', 'text-stone-400' => $quantity->isZero(), 'text-stone-900' => ! $quantity->isZero()]) dir="ltr">{{ $quantity->format($product->unit->decimals) }}</span>
                    <span class="text-xs text-stone-500">{{ $product->unit->code === Unit::BRICK ? __('bricks') : ($product->unit->symbol ?? $product->unit->displayName()) }}</span>
                </x-ui.td>
            </tr>
        @empty
            <tr><td colspan="3"><x-ui.empty-state icon="brick-wall" :title="__('No grades yet')" :description="__('Add grades such as Awal and Doom in Kiln settings.')" /></td></tr>
        @endforelse
    </x-ui.table>

    <livewire:production.entry-forms />
</div>
