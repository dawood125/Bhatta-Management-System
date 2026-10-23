@php use App\Models\EmailLog; @endphp
<div>
    <x-ui.page-header :title="__('Sales')" :description="__('Invoices emailed to the owner, with the PDF attached. A failed email is tried again automatically.')" />

    <x-sales.tabs active="emails" />

    <x-ui.card class="mb-4">
        <div class="flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between">
            <p class="text-stone-700">
                @if ($kiln->sales_email_enabled && $kiln->salesEmailAddresses() !== [])
                    <x-lucide-mail-check class="inline size-4 text-emerald-600" aria-hidden="true" />
                    {{ __('Each new sale is emailed to :to.', ['to' => implode(', ', $kiln->salesEmailAddresses())]) }}
                @else
                    <x-lucide-mail-x class="inline size-4 text-stone-400" aria-hidden="true" />
                    {{ __('New sales are not emailed automatically.') }}
                @endif
            </p>
            @if ($canSettings)
                <a href="{{ route('settings.kiln') }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ __('Change in the kiln profile') }}</a>
            @endif
        </div>
    </x-ui.card>

    @error('email')
        <p class="mb-3 text-sm text-red-600">{{ $message }}</p>
    @enderror

    <x-ui.table>
        <x-slot:toolbar>
            <div class="w-full sm:max-w-xs">
                <label for="emails-status" class="sr-only">{{ __('Status') }}</label>
                <x-ui.select id="emails-status" wire:model.live="status" :placeholder="__('All emails')" :options="[EmailLog::STATUS_SENT => __('Sent'), EmailLog::STATUS_FAILED => __('Failed'), EmailLog::STATUS_QUEUED => __('Waiting to send')]" />
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('Created') }}</x-ui.th>
            <x-ui.th>{{ __('Sale') }}</x-ui.th>
            <x-ui.th class="min-w-48">{{ __('To') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->emails as $email)
            <tr wire:key="email-{{ $email->id }}" class="transition-colors hover:bg-stone-50/70" wire:loading.class="opacity-60" wire:target="status,gotoPage,nextPage,previousPage">
                <x-ui.td class="whitespace-nowrap">
                    <span class="tabular" dir="ltr">{{ $email->created_at->format('d-m-Y H:i') }}</span>
                    <p class="text-xs text-stone-500">{{ __('By :name', ['name' => $email->creator?->name ?? __('System')]) }}</p>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap">
                    @if ($email->sale)
                        <a href="{{ route('sales.show', $email->sale) }}" wire:navigate class="tabular font-medium text-brand-700 hover:underline" dir="ltr">{{ $email->sale->number }}</a>
                        <p class="text-xs text-stone-500">{{ $email->sale->customer->displayName() }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td>
                    <p class="text-sm text-stone-800" dir="ltr">{{ $email->recipients }}</p>
                    @if ($email->last_error && $email->status !== EmailLog::STATUS_SENT)
                        <p class="text-xs text-red-700" dir="ltr">{{ Str::limit($email->last_error, 200) }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap">
                    <x-ui.badge :tone="$email->statusTone()">{{ $email->statusLabel() }}</x-ui.badge>
                    <p class="mt-1 text-xs text-stone-500">
                        {{ trans_choice(':count attempt|:count attempts', $email->attempts, ['count' => $email->attempts]) }}
                        @if ($email->sent_at)
                            · <span class="tabular" dir="ltr">{{ $email->sent_at->format('d-m-Y H:i') }}</span>
                        @endif
                    </p>
                </x-ui.td>
                <x-ui.td align="end">
                    @if ($canSend && $email->sale && ! $email->sale->isCancelled() && $email->status !== EmailLog::STATUS_QUEUED)
                        <x-ui.button size="sm" variant="ghost" icon="send" wire:click="resend({{ $email->sale_id }})" loading="resend({{ $email->sale_id }})">{{ __('Send again') }}</x-ui.button>
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty-state icon="mail" :title="__('No invoice emails yet')" :description="__('Switch on sales emails in the kiln profile, or use “Email invoice” on a sale.')" />
                </td>
            </tr>
        @endforelse

        @if ($this->emails->hasPages())
            <x-slot:footer>{{ $this->emails->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</div>
