<x-settings.shell :title="__('Email')" :description="__('The email account that sends invoice emails and backup alerts. Set it once for this kiln; nothing has to be changed on the server.')">
    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="space-y-6 lg:col-span-2">
            <x-ui.card class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-stone-900">{{ __('Email server') }}</h2>
                        <p class="text-sm text-stone-500">{{ __('From your email provider (SMTP settings).') }}</p>
                    </div>
                    <x-ui.button size="sm" variant="secondary" icon="mail" wire:click="useGmail">{{ __('Use Gmail') }}</x-ui.button>
                </div>

                @if (! $configured)
                    <p class="rounded-[var(--radius-control)] bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{{ __('Not set up yet: no emails are sent from this kiln.') }}</p>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field class="sm:col-span-2" :label="__('Email server')" for="mail-host" error="form.mail_host" :hint="__('e.g. smtp.gmail.com')">
                        <x-ui.input id="mail-host" wire:model="form.mail_host" dir="ltr" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('Port')" for="mail-port" error="form.mail_port">
                        <x-ui.input id="mail-port" wire:model="form.mail_port" inputmode="numeric" dir="ltr" />
                    </x-ui.field>
                    <x-ui.field :label="__('Security')" for="mail-encryption" error="form.mail_encryption">
                        <x-ui.select id="mail-encryption" wire:model="form.mail_encryption">
                            <option value="tls">TLS (587)</option>
                            <option value="ssl">SSL (465)</option>
                            <option value="none">{{ __('None') }}</option>
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('Username')" for="mail-username" error="form.mail_username" :hint="__('Usually the full email address.')">
                        <x-ui.input id="mail-username" wire:model="form.mail_username" dir="ltr" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('Password')" for="mail-password" error="form.mail_password" :hint="$hasPassword ? __('A password is saved. Leave this empty to keep it.') : __('For Gmail, an app password (see the note).')">
                        <x-ui.input id="mail-password" type="password" wire:model="form.mail_password" dir="ltr" autocomplete="new-password" />
                    </x-ui.field>
                    <x-ui.field :label="__('From address')" for="mail-from-address" error="form.mail_from_address" :hint="__('The address customers see the email come from.')">
                        <x-ui.input id="mail-from-address" type="email" wire:model="form.mail_from_address" dir="ltr" />
                    </x-ui.field>
                    <x-ui.field :label="__('From name')" for="mail-from-name" error="form.mail_from_name" :hint="__('Optional. Your kiln’s name if left empty.')">
                        <x-ui.input id="mail-from-name" wire:model="form.mail_from_name" />
                    </x-ui.field>
                </div>

                @if ($hasPassword)
                    <x-ui.checkbox id="remove-password" wire:model="form.remove_password" :label="__('Remove the saved password')" />
                @endif
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-stone-900">{{ __('Backup alerts') }}</h2>
                    <p class="text-sm text-stone-500">{{ __('An email is sent when a nightly backup fails or is more than a day old.') }}</p>
                </div>
                <x-ui.field :label="__('Email addresses')" for="backup-email-to" error="form.backup_email_to" :hint="__('Separate several addresses with commas (at most 5).')">
                    <x-ui.input id="backup-email-to" wire:model="form.backup_email_to" dir="ltr" placeholder="owner@example.com" />
                </x-ui.field>
                <div class="flex justify-end border-t border-stone-100 pt-4">
                    <x-ui.button type="submit" icon="check" loading="save">{{ __('Save') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        <div class="space-y-6">
            <x-ui.card class="space-y-3">
                <h2 class="text-base font-semibold text-stone-900">{{ __('Send a test email') }}</h2>
                <p class="text-sm text-stone-500">{{ __('Save first, then send a test to check the settings.') }}</p>
                <x-ui.field :label="__('To')" for="test-to" error="to">
                    <x-ui.input id="test-to" type="email" wire:model="testTo" dir="ltr" />
                </x-ui.field>
                <x-ui.button variant="secondary" icon="send" wire:click="sendTest" loading="sendTest" :disabled="! $configured">{{ __('Send test email') }}</x-ui.button>
            </x-ui.card>

            <x-ui.card class="space-y-2 text-sm text-stone-600">
                <h2 class="text-base font-semibold text-stone-900">{{ __('Using Gmail') }}</h2>
                <ol class="list-decimal space-y-1 ps-5">
                    <li>{{ __('Press “Use Gmail”.') }}</li>
                    <li>{{ __('Username and from address: the Gmail address.') }}</li>
                    <li>{{ __('Password: not the normal Gmail password, but an app password. In the Google account: Security → 2-Step Verification (turn it on) → App passwords → create one for “Bhatta Manager” and paste its 16 letters here.') }}</li>
                </ol>
            </x-ui.card>
        </div>
    </div>
</x-settings.shell>
