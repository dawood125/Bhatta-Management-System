<div class="space-y-8">
    <x-ui.page-header :title="__('Components')" :description="__('Design system preview for review. Not visible in production.')" />

    <x-ui.card>
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500">{{ __('Buttons') }}</h2>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button icon="plus">{{ __('Add user') }}</x-ui.button>
            <x-ui.button variant="secondary" icon="pencil">{{ __('Edit') }}</x-ui.button>
            <x-ui.button variant="ghost" icon="x">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" icon="user-x">{{ __('Deactivate') }}</x-ui.button>
            <x-ui.button size="sm">{{ __('Save') }}</x-ui.button>
            <x-ui.button disabled>{{ __('Save') }}</x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.card>
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500">{{ __('Status badges') }}</h2>
        <div class="flex flex-wrap gap-2">
            <x-ui.badge>{{ __('Viewer') }}</x-ui.badge>
            <x-ui.badge tone="brand">{{ __('Owner') }}</x-ui.badge>
            <x-ui.badge tone="success" icon="circle-check">{{ __('Active') }}</x-ui.badge>
            <x-ui.badge tone="warning" icon="triangle-alert">{{ __('Role changed') }}</x-ui.badge>
            <x-ui.badge tone="danger" icon="user-x">{{ __('Deactivated') }}</x-ui.badge>
            <x-ui.badge tone="info" icon="info">{{ __('Logged in') }}</x-ui.badge>
        </div>
    </x-ui.card>

    <x-ui.card>
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500">{{ __('Form fields') }}</h2>
        <form wire:submit="demoValidation" class="grid max-w-2xl gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Sample field')" for="sample" required :hint="__('Submit empty to see the error state.')">
                <x-ui.input id="sample" wire:model="sample" />
            </x-ui.field>
            <x-ui.field :label="__('Amount')" for="amount">
                <x-ui.input id="amount" inputmode="decimal" :suffix="__('Rs')" value="13,800.00" dir="ltr" />
            </x-ui.field>
            <x-ui.field :label="__('Role')" for="role-demo">
                <x-ui.select id="role-demo" :options="['owner' => __('Owner'), 'manager' => __('Manager')]" />
            </x-ui.field>
            <x-ui.field :label="__('Search')" for="search-demo">
                <x-ui.input id="search-demo" icon="search" :placeholder="__('Search name, username or phone')" />
            </x-ui.field>
            <div class="sm:col-span-2">
                <x-ui.checkbox id="check-demo" :label="__('Keep me logged in on this device')" :description="__('Optional')" />
            </div>
            <div class="sm:col-span-2">
                <x-ui.button type="submit" loading="demoValidation">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500">{{ __('Feedback') }}</h2>
        <div class="flex flex-wrap gap-3">
            <x-ui.button variant="secondary" wire:click="demoToast('success')">{{ __('Success') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="demoToast('error')">{{ __('Error') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="demoToast('warning')">{{ __('Warning') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="demoToast('info')">{{ __('Information') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="$set('showDemoModal', true)">{{ __('Open dialog') }}</x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Amount') }}</x-ui.th>
        </x-slot:head>
        <tr><x-ui.td>{{ __('Example row') }}</x-ui.td><x-ui.td align="end" numeric>{{ \App\Support\Money::fromRupees('13800')->formatWithCurrency() }}</x-ui.td></tr>
        <tr><x-ui.td>{{ __('Example row') }}</x-ui.td><x-ui.td align="end" numeric>{{ \App\Support\Money::fromRupees('-1250.5')->formatWithCurrency() }}</x-ui.td></tr>
    </x-ui.table>

    <x-ui.card :padding="false">
        <x-ui.empty-state icon="inbox" :title="__('No users found')" :description="__('Try a different search or filter.')" />
    </x-ui.card>

    <x-ui.modal wire:model="showDemoModal" :title="__('Example dialog')" :description="__('Dialogs close with Esc or by clicking outside.')" max-width="md">
        <p class="text-sm text-stone-600">{{ __('This is how confirmations and forms appear.') }}</p>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button x-on:click="open = false">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
