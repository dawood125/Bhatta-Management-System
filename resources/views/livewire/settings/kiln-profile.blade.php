<x-settings.shell :title="__('Kiln profile')" :description="__('Your kiln\'s name and contact details. They appear on screens and on invoices.')">
    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="space-y-4 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Kiln name (English)')" for="kiln-name" error="form.name" required>
                    <x-ui.input id="kiln-name" wire:model="form.name" lang="en" dir="ltr" />
                </x-ui.field>
                <x-ui.field :label="__('Kiln name (Urdu)')" for="kiln-name-ur" error="form.name_ur">
                    <x-ui.input id="kiln-name-ur" wire:model="form.name_ur" lang="ur" dir="rtl" />
                </x-ui.field>
                <x-ui.field :label="__('Address (English)')" for="kiln-address" error="form.address">
                    <x-ui.input id="kiln-address" wire:model="form.address" lang="en" dir="ltr" />
                </x-ui.field>
                <x-ui.field :label="__('Address (Urdu)')" for="kiln-address-ur" error="form.address_ur">
                    <x-ui.input id="kiln-address-ur" wire:model="form.address_ur" lang="ur" dir="rtl" />
                </x-ui.field>
                <x-ui.field :label="__('Phone')" for="kiln-phone" error="form.phone">
                    <x-ui.input id="kiln-phone" wire:model="form.phone" inputmode="tel" dir="ltr" placeholder="03XX XXXXXXX" />
                </x-ui.field>
                <x-ui.field :label="__('Email')" for="kiln-email" error="form.email" :hint="__('Optional')">
                    <x-ui.input id="kiln-email" type="email" wire:model="form.email" dir="ltr" />
                </x-ui.field>
            </div>
            <div class="flex items-center justify-between gap-4 border-t border-stone-100 pt-4">
                <p class="text-xs text-stone-500">{{ __('Currency: Pakistani Rupee (Rs)') }}</p>
                <x-ui.button type="submit" icon="check" loading="save,logo">{{ __('Save profile') }}</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h3 class="text-sm font-semibold text-stone-900">{{ __('Logo') }}</h3>

            <div class="flex items-center gap-4">
                <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-stone-100 ring-1 ring-stone-200">
                    {{-- Preview only real images that passed validation (a picked PDF must not be rendered). --}}
                    @if ($logo && $logo->isPreviewable() && ! $errors->has('logo') && ! $errors->has('form.logo'))
                        <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('New logo preview') }}" class="size-full object-contain">
                    @elseif ($kiln->logo_path && ! $removeLogo)
                        <img src="{{ $kiln->logoUrl() }}" alt="{{ __('Current logo') }}" class="size-full object-contain">
                    @else
                        <x-brand.logo class="size-10 opacity-40" />
                    @endif
                </div>
                <p class="text-xs text-stone-500">{{ __('PNG, JPG or WebP, up to 1 MB. A square logo looks best.') }}</p>
            </div>

            <label for="kiln-logo" class="flex cursor-pointer items-center justify-center gap-2 rounded-[var(--radius-control)] border border-dashed border-stone-300 px-4 py-3 text-sm font-medium text-stone-600 hover:border-brand-400 hover:text-brand-700">
                <x-lucide-image-up class="size-4" aria-hidden="true" />
                <span wire:loading.remove wire:target="logo">{{ __('Choose a logo') }}</span>
                <span wire:loading wire:target="logo">{{ __('Uploading…') }}</span>
            </label>
            <input id="kiln-logo" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="sr-only">

            @foreach (['logo', 'form.logo'] as $logoError)
                @error($logoError)
                    <p class="flex items-start gap-1.5 text-sm text-red-600"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                @enderror
            @endforeach

            @if ($kiln->logo_path && ! $logo)
                <x-ui.checkbox id="remove-logo" wire:model.live="removeLogo" :label="__('Remove the current logo')" />
            @endif

            <p class="text-xs text-stone-500">{{ __('The new logo is saved when you press "Save profile".') }}</p>
        </x-ui.card>
    </form>

    <form wire:submit="saveSales" class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-ui.card class="space-y-4 lg:col-span-2">
            <div>
                <h2 class="text-base font-semibold text-stone-900">{{ __('Sales emails and invoices') }}</h2>
                <p class="text-sm text-stone-500">{{ __('Each new sale can be emailed to the owner with its invoice as a PDF.') }}</p>
            </div>
            <x-ui.checkbox id="sales-email-enabled" wire:model.live="salesForm.sales_email_enabled" :label="__('Email each new sale')" :description="__('Sent in the background; the invoice emails page shows when each one went out.')" />
            <x-ui.field :label="__('Email addresses')" for="sales-email-to" error="salesForm.sales_email_to" :hint="__('Separate several addresses with commas (at most 5).')">
                <x-ui.input id="sales-email-to" wire:model="salesForm.sales_email_to" dir="ltr" placeholder="owner@example.com" />
            </x-ui.field>
            <x-ui.field :label="__('Note on invoices')" for="invoice-note" error="salesForm.invoice_note" :hint="__('Optional. Printed at the bottom of every invoice, e.g. terms or bank details.')">
                <x-ui.input id="invoice-note" wire:model="salesForm.invoice_note" />
            </x-ui.field>
            <div class="flex justify-end border-t border-stone-100 pt-4">
                <x-ui.button type="submit" icon="check" loading="saveSales">{{ __('Save') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</x-settings.shell>
