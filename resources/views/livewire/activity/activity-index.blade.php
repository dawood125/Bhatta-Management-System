@php use App\Support\ActivityPresenter; @endphp
<div>
    <x-ui.page-header :title="__('Activity log')" :description="__('A permanent record of who changed what, and when. Entries cannot be edited or deleted.')" />

    <x-ui.table>
        <x-slot:toolbar>
            <div class="grid w-full gap-3 sm:grid-cols-3 lg:max-w-2xl">
                <div>
                    <label for="activity-user" class="mb-1 block text-xs font-medium text-stone-500">{{ __('User') }}</label>
                    <x-ui.select id="activity-user" wire:model.live="causerId" :placeholder="__('All users')" :options="$this->userOptions" />
                </div>
                <div>
                    <label for="activity-from" class="mb-1 block text-xs font-medium text-stone-500">{{ __('From date') }}</label>
                    <x-ui.input id="activity-from" type="date" wire:model.live="from" dir="ltr" />
                </div>
                <div>
                    <label for="activity-to" class="mb-1 block text-xs font-medium text-stone-500">{{ __('To date') }}</label>
                    <x-ui.input id="activity-to" type="date" wire:model.live="to" dir="ltr" />
                </div>
            </div>
            @if ($causerId !== '' || $from !== '' || $to !== '')
                <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.th>{{ __('When') }}</x-ui.th>
            <x-ui.th>{{ __('Who') }}</x-ui.th>
            <x-ui.th>{{ __('What') }}</x-ui.th>
            <x-ui.th>{{ __('Record') }}</x-ui.th>
            <x-ui.th>{{ __('Changes') }}</x-ui.th>
        </x-slot:head>

        @forelse ($this->activities as $activity)
            @php($changes = ActivityPresenter::changes($activity))
            <tr wire:key="activity-{{ $activity->id }}" class="align-top" wire:loading.class="opacity-60" wire:target="causerId,from,to,gotoPage,nextPage,previousPage,clearFilters">
                <x-ui.td class="whitespace-nowrap">
                    <span class="tabular block text-stone-800" dir="ltr">{{ $activity->created_at?->format('d-m-Y') }}</span>
                    <span class="tabular block text-xs text-stone-500" dir="ltr">{{ $activity->created_at?->format('g:i A') }}</span>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap"><bdi>{{ $activity->causer?->name ?? __('System') }}</bdi></x-ui.td>
                <x-ui.td><x-ui.badge :tone="ActivityPresenter::eventTone($activity)">{{ ActivityPresenter::eventLabel($activity) }}</x-ui.badge></x-ui.td>
                <x-ui.td class="whitespace-nowrap">{{ ActivityPresenter::subjectLabel($activity) }}</x-ui.td>
                <x-ui.td>
                    @if ($changes === [])
                        <span class="text-stone-400">—</span>
                    @else
                        <dl class="space-y-1 text-xs">
                            @foreach ($changes as $change)
                                <div class="flex flex-wrap items-baseline gap-x-1.5">
                                    <dt class="font-medium text-stone-600">{{ $change['field'] }}:</dt>
                                    {{-- <bdi> keeps numbers/usernames in their own direction inside Urdu text. --}}
                                    @if ($activity->event !== 'created')
                                        <dd class="text-stone-400 line-through"><bdi>{{ $change['old'] }}</bdi></dd>
                                        <x-lucide-chevron-right class="size-3 self-center text-stone-400 rtl:rotate-180" aria-hidden="true" />
                                    @endif
                                    <dd class="text-stone-800"><bdi>{{ $change['new'] }}</bdi></dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty-state icon="history" :title="__('No activity found')" :description="__('Changes made in this kiln will appear here.')" />
                </td>
            </tr>
        @endforelse

        @if ($this->activities->hasPages())
            <x-slot:footer>
                {{ $this->activities->links() }}
            </x-slot:footer>
        @endif
    </x-ui.table>
</div>
