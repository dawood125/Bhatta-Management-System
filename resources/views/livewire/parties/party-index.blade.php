<div>
    <x-ui.page-header :title="__('Parties')" :description="__('Customers, suppliers, drivers and sales agents, with what each one owes or is owed.')">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button icon="user-plus" wire:click="create">{{ __('Add party') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-2xl">
        <x-ui.card class="py-4!">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total receivable') }}</p>
            <p class="mt-1 text-lg font-semibold text-emerald-800"><x-ui.money :amount="$this->totals['receivable']" /></p>
            <p class="text-xs text-stone-500">{{ __('What parties owe the kiln') }}</p>
        </x-ui.card>
        <x-ui.card class="py-4!">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ __('Total payable') }}</p>
            <p class="mt-1 text-lg font-semibold text-amber-800"><x-ui.money :amount="$this->totals['payable']" /></p>
            <p class="text-xs text-stone-500">{{ __('What the kiln owes parties') }}</p>
        </x-ui.card>
    </div>

    <nav class="-mx-4 mb-4 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden" aria-label="{{ __('Party type') }}">
        <div class="inline-flex min-w-max gap-1 rounded-lg bg-stone-200/60 p-1">
            @foreach (['' => __('All')] + collect(\App\Enums\PartyRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->pluralLabel()])->all() as $value => $label)
                <button type="button" wire:click="$set('role', '{{ $value }}')" @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition',
                    'bg-white text-stone-900 shadow-xs' => $role === $value,
                    'text-stone-600 hover:text-stone-900' => $role !== $value,
                ]) @if ($role === $value) aria-current="true" @endif>{{ $label }}</button>
            @endforeach
        </div>
    </nav>

    <x-ui.table>
        <x-slot:toolbar>
            <div class="w-full sm:max-w-xs">
                <label for="party-search" class="sr-only">{{ __('Search') }}</label>
                <x-ui.input id="party-search" icon="search" type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search name or phone')" />
            </div>
            <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                <div class="sm:w-44">
                    <label for="party-area" class="sr-only">{{ __('Area') }}</label>
                    <x-ui.select id="party-area" wire:model.live="area" :placeholder="__('All areas')" :options="\App\Models\Area::query()->orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all()" />
                </div>
                <div class="sm:w-36">
                    <label for="party-status" class="sr-only">{{ __('Status') }}</label>
                    <x-ui.select id="party-status" wire:model.live="status" :options="['active' => __('Active'), 'archived' => __('Archived'), 'all' => __('All')]" />
                </div>
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th class="min-w-56">{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th>{{ __('Phone') }}</x-ui.th>
            <x-ui.th>{{ __('Area') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Balance') }}</x-ui.th>
            <x-ui.th align="end"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->parties as $party)
            <tr wire:key="party-{{ $party->id }}" @class(['transition-colors hover:bg-stone-50/70', 'opacity-60' => ! $party->is_active]) wire:loading.class="opacity-60" wire:target="role,search,area,status,gotoPage,nextPage,previousPage">
                <x-ui.td>
                    <a href="{{ route('parties.show', $party) }}" wire:navigate class="hover:underline"><x-settings.name-cell :record="$party" /></a>
                    @if ($party->salesAgent)
                        <p class="text-xs text-stone-500">{{ __('Agent: :name', ['name' => $party->salesAgent->displayName()]) }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($this->rolesMap[$party->id] ?? [] as $partyRole)
                            <x-ui.badge :tone="$partyRole->tone()">{{ $partyRole->label() }}</x-ui.badge>
                        @endforeach
                    </div>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $party->phone ?? '—' }}</span></x-ui.td>
                <x-ui.td class="whitespace-nowrap">{{ $party->area?->displayName() ?? '—' }}</x-ui.td>
                <x-ui.td align="end"><x-party-balance :amount="$this->balances[$party->id]" /></x-ui.td>
                <x-ui.td align="end">
                    <div class="flex items-center justify-end gap-1">
                        <x-ui.button size="sm" variant="ghost" icon="book-open" :href="route('parties.show', $party)" wire:navigate :aria-label="__('Statement of :name', ['name' => $party->displayName()])" :title="__('Statement')" />
                        @if ($canManage)
                            <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $party->id }})" :aria-label="__('Edit :name', ['name' => $party->displayName()])" :title="__('Edit')" />
                            @if ($party->is_active)
                                <x-ui.button size="sm" variant="ghost" icon="archive" wire:click="archive({{ $party->id }})" wire:confirm="{{ __('Archive :name? It will no longer appear in new entries. Old records stay unchanged.', ['name' => $party->displayName()]) }}" :aria-label="__('Archive :name', ['name' => $party->displayName()])" :title="__('Archive')" />
                            @else
                                <x-ui.button size="sm" variant="ghost" icon="archive-restore" wire:click="restore({{ $party->id }})" :aria-label="__('Restore :name', ['name' => $party->displayName()])" :title="__('Restore')" />
                            @endif
                        @endif
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty-state icon="users" :title="__('No parties found')" :description="$search !== '' || $area !== '' ? __('Try a different search or filter.') : __('Add your customers, suppliers, drivers and sales agents.')">
                        @if ($canManage && $search === '' && $area === '')
                            <x-ui.button icon="user-plus" variant="secondary" wire:click="create">{{ __('Add party') }}</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </td>
            </tr>
        @endforelse

        @if ($this->parties->hasPages())
            <x-slot:footer>{{ $this->parties->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    @if ($canManage)
        <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit party') : __('Add party')" max-width="xl">
            <form id="party-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <fieldset class="sm:col-span-2">
                    <legend class="mb-1.5 text-sm font-medium text-stone-700">{{ __('This party is a') }} <span class="text-red-600" aria-hidden="true">*</span></legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach (\App\Enums\PartyRole::cases() as $partyRole)
                            <label @class([
                                'flex cursor-pointer items-center gap-2 rounded-[var(--radius-control)] border px-3 py-2 text-sm font-medium transition',
                                'border-brand-500 bg-brand-50 text-brand-800' => in_array($partyRole->value, $form['roles'] ?? [], true),
                                'border-stone-300 text-stone-600 hover:border-stone-400' => ! in_array($partyRole->value, $form['roles'] ?? [], true),
                            ])>
                                <input type="checkbox" value="{{ $partyRole->value }}" wire:model.live="form.roles" class="size-4 accent-brand-600">
                                {{ $partyRole->label() }}
                            </label>
                        @endforeach
                    </div>
                    @error('form.roles')
                        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                    @enderror
                </fieldset>

                <x-ui.field :label="__('Name (English)')" for="party-name" error="form.name" required>
                    <x-ui.input id="party-name" wire:model.live.debounce.400ms="form.name" lang="en" dir="ltr" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('Name (Urdu)')" for="party-name-ur" error="form.name_ur" :hint="__('Shown when the language is Urdu.')">
                    <x-ui.input id="party-name-ur" wire:model="form.name_ur" lang="ur" dir="rtl" autocomplete="off" />
                </x-ui.field>

                @if ($showForm && $this->similarParties->isNotEmpty())
                    <div class="flex items-start gap-2 rounded-[var(--radius-control)] bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 sm:col-span-2" role="status">
                        <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-medium">{{ __('Similar names already exist. Make sure this is not the same person:') }}</p>
                            <p>{{ $this->similarParties->map(fn ($p) => $p->displayName().($p->phone ? ' ('.$p->phone.')' : ''))->implode('، ') }}</p>
                        </div>
                    </div>
                @endif

                <x-ui.field :label="__('Phone')" for="party-phone" error="form.phone">
                    <x-ui.input id="party-phone" wire:model="form.phone" inputmode="tel" dir="ltr" placeholder="03XX XXXXXXX" />
                </x-ui.field>
                <x-ui.field :label="__('Second phone')" for="party-phone-2" error="form.phone_2" :hint="__('Optional')">
                    <x-ui.input id="party-phone-2" wire:model="form.phone_2" inputmode="tel" dir="ltr" />
                </x-ui.field>

                <x-ui.field :label="__('Address')" for="party-address" error="form.address" class="sm:col-span-2">
                    <x-ui.input id="party-address" wire:model="form.address" />
                </x-ui.field>

                <x-ui.field :label="__('Area')" for="party-area-select" error="form.area_id" :hint="__('Add areas in Kiln settings.')">
                    <x-ui.select id="party-area-select" wire:model="form.area_id" :placeholder="__('No area')" :options="$this->areaOptions" />
                </x-ui.field>

                @if (in_array('customer', $form['roles'] ?? [], true))
                    <x-ui.field :label="__('Sales agent')" for="party-agent" error="form.sales_agent_id" :hint="__('Who brought this customer, if anyone.')">
                        <x-ui.select id="party-agent" wire:model="form.sales_agent_id" :placeholder="__('No agent')" :options="$this->agentOptions" />
                    </x-ui.field>
                @endif

                <fieldset class="rounded-[var(--radius-control)] bg-stone-50 p-4 ring-1 ring-stone-200 sm:col-span-2">
                    <legend class="px-1 text-sm font-medium text-stone-700">{{ __('Opening balance') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.field :label="__('Amount')" for="party-opening" error="form.opening_amount" required>
                            <x-ui.input id="party-opening" wire:model="form.opening_amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" />
                        </x-ui.field>
                        <x-ui.field :label="__('Who owes whom')" for="party-direction" error="form.opening_direction" required>
                            <x-ui.select id="party-direction" wire:model="form.opening_direction" :options="['receivable' => __('They owe us (receivable)'), 'payable' => __('We owe them (payable)')]" />
                        </x-ui.field>
                        <x-ui.field :label="__('As of date')" for="party-opening-date" error="form.opening_date" required>
                            <x-ui.input id="party-opening-date" type="date" wire:model="form.opening_date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                        </x-ui.field>
                    </div>
                    <p class="mt-2 text-xs text-stone-500">{{ $editingId ? __('Changing it reverses the old opening entry and records a new one; both stay in the history.') : __('Use 0 if nothing is owed either way.') }}</p>
                </fieldset>

                <x-ui.field :label="__('Note')" for="party-notes" error="form.notes" :hint="__('Optional')" class="sm:col-span-2">
                    <x-ui.input id="party-notes" wire:model="form.notes" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="party-form" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
