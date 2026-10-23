<div>
    <x-reports.header :title="__('Day close')" :description="__('At the end of the day, count the cash and check it against the books, then close the day. Only the owner can add entries to a closed day.')"
        :period="$this->day()?->format('d-m-Y')" />

    <x-ui.card class="mb-4 print:hidden">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-44">
                <label for="close-date" class="mb-1 block text-xs font-medium text-stone-500">{{ __('Day') }}</label>
                <x-ui.input id="close-date" type="date" wire:model.live="date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
            </div>
            <p class="text-sm text-stone-600">
                @if ($this->closedThrough)
                    <x-lucide-lock class="inline size-4 text-stone-500" aria-hidden="true" />
                    {!! __('Books closed up to :date.', ['date' => '<span class="tabular font-medium text-stone-900" dir="ltr">'.e($this->closedThrough->format('d-m-Y')).'</span>']) !!}
                @else
                    <x-lucide-lock-open class="inline size-4 text-stone-400" aria-hidden="true" /> {{ __('No day has been closed yet.') }}
                @endif
            </p>
        </div>
    </x-ui.card>

    @if ($this->day() === null)
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar" :title="__('Choose a valid day')" :description="__('Today or an earlier day.')" />
        </x-ui.card>
    @elseif ($isClosed)
        @php($close = $this->closeOfDay)
        <x-ui.card class="mb-4">
            <p class="text-sm text-stone-700">
                @if ($close && ! $close->isReopened())
                    {{ __('This day was closed by :name on :date.', ['name' => $close->closer?->name ?? __('System'), 'date' => $close->created_at->format('d-m-Y H:i')]) }}
                @else
                    {{ __('This day is closed: it is on or before the last closed day.') }}
                @endif
            </p>
            @if ($close?->notes)
                <p class="mt-1 text-sm text-stone-600"><bdi>{{ $close->notes }}</bdi></p>
            @endif
        </x-ui.card>

        @if ($close)
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th class="min-w-40">{{ __('Account') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Opening') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('In') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Out') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('In the books') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Counted') }}</x-ui.th>
                    <x-ui.th align="end">{{ __('Difference') }}</x-ui.th>
                </x-slot:head>
                @foreach ($close->counts as $count)
                    @php($difference = $count->difference())
                    <tr wire:key="closed-{{ $count->id }}">
                        <x-ui.td class="font-medium text-stone-900">{{ $count->treasuryAccount->displayName() }}</x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$count->opening" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$count->money_in" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end"><x-ui.money :amount="$count->money_out" :currency="false" muted /></x-ui.td>
                        <x-ui.td align="end" class="font-medium"><x-ui.money :amount="$count->expected" :currency="false" /></x-ui.td>
                        <x-ui.td align="end">@if ($count->counted)<x-ui.money :amount="$count->counted" :currency="false" />@else<span class="text-stone-400">—</span>@endif</x-ui.td>
                        <x-ui.td align="end">
                            @if ($difference === null)
                                <span class="text-stone-400">—</span>
                            @else
                                <span @class(['tabular font-semibold', 'text-emerald-700' => $difference->isZero(), 'text-red-700' => $difference->isNegative(), 'text-amber-700' => $difference->isPositive()]) dir="ltr">{{ $difference->isPositive() ? '+' : '' }}{{ $difference->format() }}</span>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    @else
        <x-ui.table wire:loading.class="opacity-60" wire:target="date">
            <x-slot:head>
                <x-ui.th class="min-w-40">{{ __('Account') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Opening') }}</x-ui.th>
                <x-ui.th align="end">{{ __('In') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Out') }}</x-ui.th>
                <x-ui.th align="end">{{ __('In the books') }}</x-ui.th>
                <x-ui.th align="end" class="min-w-36">{{ __('Counted') }}</x-ui.th>
                <x-ui.th align="end">{{ __('Difference') }}</x-ui.th>
            </x-slot:head>
            @forelse ($this->rows as $row)
                @php($difference = $this->difference($row['account']->id, $row['closing']))
                <tr wire:key="day-{{ $row['account']->id }}">
                    <x-ui.td>
                        <a href="{{ route('accounts.book', ['account' => $row['account'], 'from' => $date, 'to' => $date]) }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700 hover:underline">{{ $row['account']->displayName() }}</a>
                        <p class="text-xs text-stone-500">{{ $row['account']->kind->label() }}</p>
                    </x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$row['opening']" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$row['in']" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end"><x-ui.money :amount="$row['out']" :currency="false" muted /></x-ui.td>
                    <x-ui.td align="end" class="font-semibold"><x-ui.money :amount="$row['closing']" :currency="false" /></x-ui.td>
                    <x-ui.td align="end">
                        <label for="count-{{ $row['account']->id }}" class="sr-only">{{ __('Counted in :account', ['account' => $row['account']->displayName()]) }}</label>
                        <x-ui.input id="count-{{ $row['account']->id }}" wire:model.live.debounce.500ms="counts.{{ $row['account']->id }}" inputmode="decimal" dir="ltr" class="text-end tabular" autocomplete="off" :placeholder="__('Not counted')" />
                        @error('counts.'.$row['account']->id)
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </x-ui.td>
                    <x-ui.td align="end">
                        @if ($difference === null)
                            <span class="text-stone-400">—</span>
                        @else
                            <span @class(['tabular font-semibold', 'text-emerald-700' => $difference->isZero(), 'text-red-700' => $difference->isNegative(), 'text-amber-700' => $difference->isPositive()]) dir="ltr">{{ $difference->isPositive() ? '+' : '' }}{{ $difference->format() }}</span>
                        @endif
                    </x-ui.td>
                </tr>
            @empty
                <tr><td colspan="7"><x-ui.empty-state icon="wallet" :title="__('No cash or bank accounts yet')" /></td></tr>
            @endforelse
        </x-ui.table>
        <p class="mt-2 text-xs text-stone-500">{{ __('Opening + in − out = in the books. A difference is not posted: if cash is missing or extra, record what it was (for example an expense) before closing.') }}</p>

        <x-ui.card class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between print:hidden">
            <x-ui.field :label="__('Note')" for="close-notes" error="notes" :hint="__('Optional')" class="sm:max-w-lg sm:flex-1">
                <x-ui.input id="close-notes" wire:model="notes" />
            </x-ui.field>
            <x-ui.button icon="lock" wire:click="confirmClose">{{ __('Close the day') }}</x-ui.button>
        </x-ui.card>
        @error('close_date')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    @endif

    {{-- History --}}
    <h2 class="mb-2 mt-8 text-base font-semibold text-stone-900">{{ __('Closed days') }}</h2>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Day') }}</x-ui.th>
            <x-ui.th>{{ __('Closed by') }}</x-ui.th>
            <x-ui.th align="end">{{ __('Difference') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>
        @forelse ($this->history as $close)
            @php($counted = $close->counts->filter(fn ($c) => $c->counted !== null))
            @php($total = App\Support\Money::sum($counted->map(fn ($c) => $c->difference())))
            <tr wire:key="history-{{ $close->id }}" @class(['bg-stone-50/60 text-stone-500' => $close->isReopened()])>
                <x-ui.td class="whitespace-nowrap">
                    <button type="button" wire:click="$set('date', '{{ $close->close_date->format('Y-m-d') }}')" class="tabular font-medium text-brand-700 hover:underline" dir="ltr">{{ $close->close_date->format('d-m-Y') }}</button>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap">{{ $close->closer?->name ?? __('System') }} <span class="tabular text-xs text-stone-500" dir="ltr">{{ $close->created_at->format('d-m-Y H:i') }}</span></x-ui.td>
                <x-ui.td align="end">
                    @if ($counted->isEmpty())
                        <span class="text-stone-400">{{ __('Not counted') }}</span>
                    @else
                        <span @class(['tabular font-medium', 'text-emerald-700' => $total->isZero(), 'text-red-700' => $total->isNegative(), 'text-amber-700' => $total->isPositive()]) dir="ltr">{{ $total->isPositive() ? '+' : '' }}{{ $total->format() }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td>
                    @if ($close->isReopened())
                        <x-ui.badge tone="neutral">{{ __('Reopened') }}</x-ui.badge>
                        <p class="text-xs">{{ __('By :name', ['name' => $close->reopener?->name ?? __('System')]) }}: <bdi>{{ $close->reopen_reason }}</bdi></p>
                    @else
                        <x-ui.badge tone="success" icon="lock">{{ __('Closed') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td align="end">
                    @if ($canReopen && ! $close->isReopened() && $this->closedThrough?->isSameDay($close->close_date))
                        <x-ui.button size="sm" variant="ghost" icon="lock-open" wire:click="confirmReopen({{ $close->id }})">{{ __('Reopen') }}</x-ui.button>
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr><td colspan="5"><x-ui.empty-state icon="lock" :title="__('No day closed yet')" :description="__('Close each day after counting the cash.')" /></td></tr>
        @endforelse
    </x-ui.table>

    <x-ui.modal wire:model="showConfirm" :title="__('Close the day')" :description="__('After closing, nothing can be added on or before :date except by the owner. The owner can reopen the day if needed.', ['date' => $this->day()?->format('d-m-Y')])" max-width="md">
        <dl class="divide-y divide-stone-100 text-sm">
            @foreach ($this->rows as $row)
                @php($difference = $this->difference($row['account']->id, $row['closing']))
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-stone-700">{{ $row['account']->displayName() }}</dt>
                    <dd class="text-end">
                        <x-ui.money :amount="$row['closing']" />
                        <span class="block text-xs @if ($difference?->isNegative()) text-red-700 @elseif ($difference?->isPositive()) text-amber-700 @else text-stone-500 @endif">
                            @if ($difference === null)
                                {{ __('Not counted') }}
                            @else
                                {{ __('Difference') }}: <span class="tabular" dir="ltr">{{ $difference->isPositive() ? '+' : '' }}{{ $difference->format() }}</span>
                            @endif
                        </span>
                    </dd>
                </div>
            @endforeach
        </dl>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Back') }}</x-ui.button>
            <x-ui.button icon="lock" wire:click="closeDay" loading="closeDay">{{ __('Close the day') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @if ($canReopen)
        <x-ui.modal wire:model="showReopen" :title="__('Reopen the day')" :description="__('Entries can be dated on this day again. The close stays in the history with your reason.')" max-width="md">
            <form id="reopen-form" wire:submit="reopen" class="space-y-4">
                <x-ui.field :label="__('Reason')" for="reopen-reason" error="reopenForm.reason" required :hint="__('For example: a receipt came in late.')">
                    <x-ui.input id="reopen-reason" wire:model="reopenForm.reason" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Back') }}</x-ui.button>
                <x-ui.button type="submit" form="reopen-form" icon="lock-open" loading="reopen">{{ __('Reopen') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
