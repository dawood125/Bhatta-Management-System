@php use App\Actions\Trolleys\RecordTrolleyCost; use App\Models\TrolleyCost; @endphp
<div>
    <div class="print:hidden">
        <x-ui.page-header :title="__('Sales')" :description="__('Each driver earns the carriage of their trips. At the end of the month, diesel and maintenance are taken out and the rest is paid.')" />
        <x-sales.tabs active="settlement" />

        <x-ui.card class="mb-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <x-ui.field :label="__('Month')" for="settlement-month" class="sm:w-56">
                    <x-ui.input id="settlement-month" type="month" wire:model.live="month" dir="ltr" max="{{ now()->format('Y-m') }}" />
                </x-ui.field>
                <div class="flex flex-wrap gap-2">
                    @if ($canCost && $trolleyOptions !== [])
                        <x-ui.button icon="fuel" wire:click="openCost">{{ __('Add diesel or maintenance') }}</x-ui.button>
                    @endif
                    @if ($this->rows !== [])
                        <x-ui.button variant="secondary" icon="printer" x-on:click="window.print()">{{ __('Print slips') }}</x-ui.button>
                    @endif
                    @if ($canPay && ($link = $this->payLink($this->rows)))
                        <x-ui.button variant="secondary" icon="hand-coins" :href="$link" wire:navigate>{{ __('Pay all drivers') }}</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th class="min-w-44">{{ __('Driver') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Trips') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Brought forward') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Carriage') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Diesel') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Maintenance') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Other') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Paid') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
                <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
            </x-slot:head>

            @forelse ($this->rows as $row)
                <tr wire:key="settlement-{{ $row['driver']->id }}" class="transition-colors hover:bg-stone-50/70">
                    <x-ui.td>
                        <a href="{{ route('parties.show', $row['driver']) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $row['driver']->displayName() }}</a>
                        @if ($row['trolleys'] !== '')
                            <p class="text-xs text-stone-500" dir="ltr">{{ $row['trolleys'] }}</p>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end" class="tabular">{{ number_format($row['trips']) }}</x-ui.td>
                    @foreach (['brought_forward', 'carriage', 'diesel', 'maintenance', 'other', 'paid'] as $column)
                        <x-ui.td align="end"><x-ui.money :amount="$row[$column]" :currency="false" :muted="$row[$column]->isZero()" /></x-ui.td>
                    @endforeach
                    <x-ui.td align="end" class="font-semibold">
                        <x-ui.money :amount="$row['closing']" :currency="false" :muted="$row['closing']->isZero()" />
                        @if ($row['closing']->isNegative())
                            <p class="text-xs font-normal text-amber-700">{{ __('Driver owes the kiln') }}</p>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="end">
                        @if ($canPay && $row['payable']->isPositive())
                            <x-ui.button size="sm" variant="secondary" icon="hand-coins" :href="$this->payLink([$row])" wire:navigate>{{ __('Pay') }}</x-ui.button>
                        @endif
                    </x-ui.td>
                </tr>
            @empty
                <tr>
                    <td colspan="10"><x-ui.empty-state icon="truck" :title="__('No drivers this month')" :description="__('Add a trolley below, or record a delivery with a driver.')" /></td>
                </tr>
            @endforelse
        </x-ui.table>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-900">{{ __('Trolleys') }}</h2>
                    @if ($canManage)
                        <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="openTrolley">{{ __('Add trolley') }}</x-ui.button>
                    @endif
                </div>
                <x-ui.card class="divide-y divide-stone-100 py-1!">
                    @forelse ($this->trolleys as $trolley)
                        <div wire:key="trolley-{{ $trolley->id }}" @class(['flex items-center justify-between gap-3 py-2 text-sm', 'opacity-60' => ! $trolley->is_active])>
                            <span><span class="font-medium text-stone-900" dir="ltr">{{ $trolley->number }}</span> · {{ $trolley->driver->displayName() }}
                                @unless ($trolley->is_active) <x-ui.badge tone="neutral">{{ __('Archived') }}</x-ui.badge> @endunless
                            </span>
                            @if ($canManage)
                                <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="openTrolley({{ $trolley->id }})" :aria-label="__('Edit :name', ['name' => $trolley->number])" />
                            @endif
                        </div>
                    @empty
                        <p class="py-3 text-sm text-stone-500">{{ __('No trolleys yet.') }}</p>
                    @endforelse
                </x-ui.card>
            </section>

            <section>
                <h2 class="mb-2 py-1 text-sm font-semibold text-stone-900">{{ __('Costs this month') }}</h2>
                <x-ui.card class="divide-y divide-stone-100 py-1!">
                    @forelse ($this->costs as $cost)
                        <div wire:key="cost-{{ $cost->id }}" @class(['flex items-start justify-between gap-3 py-2 text-sm', 'opacity-60' => $cost->entry->isReversed()])>
                            <span>
                                <span class="font-medium text-stone-900">{{ TrolleyCost::kinds()[$cost->kind] }}</span> · <span dir="ltr">{{ $cost->trolley->number }}</span> · {{ $cost->driver->displayName() }}
                                <span class="block text-xs text-stone-500"><span dir="ltr">{{ $cost->entry->number }} · {{ $cost->cost_date->format('d-m-Y') }}</span>@if ($cost->description) · {{ $cost->description }}@endif</span>
                                @if ($cost->entry->isReversed()) <x-ui.badge tone="danger" icon="undo-2">{{ __('Reversed') }}</x-ui.badge> @endif
                            </span>
                            <x-ui.money :amount="$cost->amount" :currency="false" class="font-medium" />
                        </div>
                    @empty
                        <p class="py-3 text-sm text-stone-500">{{ __('No costs this month.') }}</p>
                    @endforelse
                </x-ui.card>
                <p class="mt-2 text-xs text-stone-500">{{ __('To correct a cost, reverse its entry from Transactions.') }}</p>
            </section>
        </div>
    </div>

    {{-- One slip per driver, printed only --}}
    <div class="hidden print:block">
        @foreach ($this->rows as $row)
            <section class="break-after-page p-4 text-sm">
                <h1 class="text-lg font-semibold">{{ __('Driver settlement') }} · <span dir="ltr">{{ $this->monthStart()->format('m-Y') }}</span></h1>
                <p class="mb-3">{{ $row['driver']->displayName() }} @if ($row['trolleys'] !== '') · <span dir="ltr">{{ $row['trolleys'] }}</span> @endif · {{ __('Trips') }}: {{ number_format($row['trips']) }}</p>
                <dl class="max-w-sm space-y-1">
                    @foreach ([[__('Brought forward'), 'brought_forward'], [__('Carriage'), 'carriage'], [__('Diesel'), 'diesel'], [__('Maintenance'), 'maintenance'], [__('Other'), 'other'], [__('Paid'), 'paid'], [__('Balance'), 'closing']] as [$label, $key])
                        <div @class(['flex justify-between', 'border-t pt-1 font-semibold' => $key === 'closing'])><dt>{{ $label }}</dt><dd><x-ui.money :amount="$row[$key]" /></dd></div>
                    @endforeach
                </dl>
                <p class="mt-10">{{ __('Driver signature') }}: ____________________</p>
            </section>
        @endforeach
    </div>

    <x-ui.modal wire:model="showCost" :title="__('Add diesel or maintenance')">
        @if ($showCost)
            <form id="trolley-cost-form" wire:submit="saveCost" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Trolley')" for="cost-trolley" error="costForm.trolley_id" required class="sm:col-span-2">
                    <x-ui.select id="cost-trolley" wire:model="costForm.trolley_id" :placeholder="__('Choose a trolley')" :options="$trolleyOptions" />
                </x-ui.field>
                <x-ui.field :label="__('Date')" for="cost-date" error="costForm.cost_date" required>
                    <x-ui.input id="cost-date" type="date" wire:model="costForm.cost_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Type')" for="cost-kind" error="costForm.kind" required>
                    <x-ui.select id="cost-kind" wire:model="costForm.kind" :options="TrolleyCost::kinds()" />
                </x-ui.field>
                <x-ui.field :label="__('Amount')" for="cost-amount" error="costForm.amount" required>
                    <x-ui.input id="cost-amount" wire:model="costForm.amount" inputmode="decimal" dir="ltr" class="tabular" :suffix="__('Rs')" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Paid how')" for="cost-paid-from" error="costForm.paid_from" required>
                    <x-ui.select id="cost-paid-from" wire:model.live="costForm.paid_from" :options="[RecordTrolleyCost::FROM_ACCOUNT => __('Paid from an account'), RecordTrolleyCost::ON_CREDIT => __('On credit from a supplier')]" />
                </x-ui.field>
                @if ($costForm['paid_from'] === RecordTrolleyCost::ON_CREDIT)
                    <x-ui.field :label="__('Supplier')" for="cost-supplier" error="costForm.supplier_id" required class="sm:col-span-2">
                        <x-ui.select id="cost-supplier" wire:model="costForm.supplier_id" :placeholder="__('Choose a supplier')" :options="$supplierOptions" />
                    </x-ui.field>
                @else
                    <x-ui.field :label="__('Account')" for="cost-account" error="costForm.account_id" required class="sm:col-span-2">
                        <x-ui.select id="cost-account" wire:model="costForm.account_id" :placeholder="__('Choose an account')" :options="$accountOptions" />
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('Note')" for="cost-description" error="costForm.description" :hint="__('Optional')" class="sm:col-span-2">
                    <x-ui.input id="cost-description" wire:model="costForm.description" />
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="trolley-cost-form" icon="check" loading="saveCost">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showTrolley" :title="$editingTrolley ? __('Edit trolley') : __('Add trolley')">
        @if ($showTrolley)
            <form id="trolley-form" wire:submit="saveTrolley" class="grid gap-4">
                <x-ui.field :label="__('Trolley number')" for="trolley-number" error="trolleyForm.number" required>
                    <x-ui.input id="trolley-number" wire:model="trolleyForm.number" dir="ltr" />
                </x-ui.field>
                <x-ui.field :label="__('Driver')" for="trolley-driver" error="trolleyForm.driver_id" required :hint="__('Parties with the driver role.')">
                    <x-ui.select id="trolley-driver" wire:model="trolleyForm.driver_id" :placeholder="__('Choose a driver')" :options="$driverOptions" />
                </x-ui.field>
                <label class="flex items-center gap-2 text-sm text-stone-700"><input type="checkbox" wire:model="trolleyForm.is_active" class="size-4 accent-brand-600"> {{ __('In use') }}</label>
            </form>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="trolley-form" icon="check" loading="saveTrolley">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
