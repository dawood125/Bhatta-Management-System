@php($isCustomer = $this->partyRole() === \App\Enums\PartyRole::Customer)
<div>
    <x-ui.modal wire:model="show" :title="$isCustomer ? __('New customer') : __('New supplier')" :description="__('More details (address, area, second phone) can be added later on the Parties page.')">
        <form id="quick-party-form-{{ $role }}" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Name (English)')" for="quick-party-name-{{ $role }}" error="form.name" required>
                <x-ui.input id="quick-party-name-{{ $role }}" wire:model.live.debounce.400ms="form.name" lang="en" dir="ltr" autocomplete="off" />
            </x-ui.field>
            <x-ui.field :label="__('Name (Urdu)')" for="quick-party-name-ur-{{ $role }}" error="form.name_ur" :hint="__('Optional')">
                <x-ui.input id="quick-party-name-ur-{{ $role }}" wire:model="form.name_ur" lang="ur" dir="rtl" autocomplete="off" />
            </x-ui.field>

            @if ($show && $this->similarParties->isNotEmpty())
                <div class="flex items-start gap-2 rounded-[var(--radius-control)] bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 sm:col-span-2" role="status">
                    <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div>
                        <p class="font-medium">{{ __('Similar names already exist. Make sure this is not the same person:') }}</p>
                        <p>{{ $this->similarParties->map(fn ($p) => $p->displayName().($p->phone ? ' ('.$p->phone.')' : ''))->implode('، ') }}</p>
                    </div>
                </div>
            @endif

            <x-ui.field :label="__('Phone')" for="quick-party-phone-{{ $role }}" error="form.phone" :hint="__('Optional')" class="sm:col-span-2">
                <x-ui.input id="quick-party-phone-{{ $role }}" wire:model="form.phone" inputmode="tel" dir="ltr" placeholder="03XX XXXXXXX" />
            </x-ui.field>

            <x-ui.field :label="__('Opening balance')" for="quick-party-opening-{{ $role }}" error="form.opening_amount" :hint="__('Leave empty if nothing is owed.')">
                <x-ui.input id="quick-party-opening-{{ $role }}" wire:model="form.opening_amount" inputmode="decimal" :suffix="__('Rs')" dir="ltr" />
            </x-ui.field>
            <x-ui.field :label="__('Who owes whom')" for="quick-party-direction-{{ $role }}" error="form.opening_direction">
                <x-ui.select id="quick-party-direction-{{ $role }}" wire:model="form.opening_direction" :options="['receivable' => __('They owe us (receivable)'), 'payable' => __('We owe them (payable)')]" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="quick-party-form-{{ $role }}" icon="check" loading="save">{{ $isCustomer ? __('Add customer') : __('Add supplier') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
