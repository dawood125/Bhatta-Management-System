@php use App\Enums\AttendanceStatus; @endphp
<div>
    <x-ui.page-header :title="__('Labour')" :description="__('Attendance of salaried workers, and their salaries for each month.')" />

    <x-labour.tabs active="attendance" />

    @if ($this->workers->isEmpty())
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="calendar-check" :title="__('No salaried workers')" :description="__('Workers of a category paid per month (salary) appear here.')">
                <x-ui.button variant="secondary" icon="hard-hat" :href="route('labour.index')" wire:navigate>{{ __('Workers') }}</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {{-- Attendance of one day --}}
            <x-ui.card :padding="false">
                <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-stone-900">{{ __('Attendance') }}</h2>
                        <p class="text-sm text-stone-500">{{ __('Days not marked count as present.') }}</p>
                    </div>
                    <div class="sm:w-44">
                        <label for="attendance-date" class="mb-1 block text-xs font-medium text-stone-500">{{ __('Date') }}</label>
                        <x-ui.input id="attendance-date" type="date" wire:model.live="date" dir="ltr" max="{{ now()->format('Y-m-d') }}" />
                    </div>
                </div>

                @if (! $dateValid)
                    <x-ui.empty-state icon="calendar" :title="__('Choose a valid date')" />
                @else
                    <form wire:submit="saveAttendance">
                        @error('marks')
                            <p class="mx-4 mt-4 flex items-start gap-1.5 rounded-[var(--radius-control)] bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                        @enderror
                        @error('attendance_date')
                            <p class="mx-4 mt-4 flex items-start gap-1.5 rounded-[var(--radius-control)] bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                        @enderror

                        <ul class="divide-y divide-stone-100" wire:loading.class="opacity-60" wire:target="date">
                            @foreach ($this->workers as $worker)
                                <li wire:key="att-{{ $worker->id }}" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-stone-900">{{ $worker->displayName() }}</p>
                                        <p class="text-xs text-stone-500">{{ $worker->category->displayName() }}</p>
                                    </div>
                                    <fieldset>
                                        <legend class="sr-only">{{ __('Attendance of :name', ['name' => $worker->displayName()]) }}</legend>
                                        <div class="inline-flex gap-1 rounded-lg bg-stone-100 p-1">
                                            @foreach (AttendanceStatus::cases() as $status)
                                                <label @class([
                                                    'rounded-md px-3 py-1.5 text-sm font-medium text-stone-500 transition has-checked:bg-white has-checked:shadow-xs has-focus-visible:ring-2 has-focus-visible:ring-brand-500',
                                                    'cursor-pointer hover:text-stone-800' => $canRecord,
                                                    'has-checked:text-emerald-800' => $status === AttendanceStatus::Present,
                                                    'has-checked:text-amber-800' => $status === AttendanceStatus::Half,
                                                    'has-checked:text-red-700' => $status === AttendanceStatus::Absent,
                                                ])>
                                                    <input type="radio" class="sr-only" name="mark-{{ $worker->id }}" value="{{ $status->value }}" wire:model="marks.{{ $worker->id }}" @disabled(! $canRecord)>
                                                    {{ $status->label() }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                </li>
                            @endforeach
                        </ul>

                        @if ($canRecord)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-200 p-4">
                                <x-ui.button size="sm" variant="ghost" icon="user-check" wire:click="markAll('present')">{{ __('Mark all present') }}</x-ui.button>
                                <x-ui.button type="submit" icon="check" loading="saveAttendance">{{ __('Save attendance') }}</x-ui.button>
                            </div>
                        @endif
                    </form>
                @endif
            </x-ui.card>

            {{-- Salaries of one month --}}
            <x-ui.card :padding="false">
                <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-stone-900">{{ __('Salaries') }}</h2>
                        <p class="text-sm text-stone-500">{{ __('Monthly salary less 1/30 for each absent day and 1/60 for each half day.') }}</p>
                    </div>
                    <div class="sm:w-44">
                        <label for="salary-month" class="mb-1 block text-xs font-medium text-stone-500">{{ __('Month') }}</label>
                        <x-ui.input id="salary-month" type="month" wire:model.live="month" dir="ltr" max="{{ now()->format('Y-m') }}" />
                    </div>
                </div>

                @error('month')
                    <p class="mx-4 mt-4 flex items-start gap-1.5 rounded-[var(--radius-control)] bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ $message }}</p>
                @enderror

                @if ($monthLabel === null)
                    <x-ui.empty-state icon="calendar" :title="__('Choose a valid month')" />
                @else
                    <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="month,postSalaries,saveAttendance">
                        <table class="w-full text-sm">
                            <thead class="bg-stone-50 text-xs font-medium uppercase tracking-wide text-stone-500">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-start">{{ __('Worker') }}</th>
                                    <th scope="col" class="px-4 py-2 text-end">{{ __('Monthly salary') }}</th>
                                    <th scope="col" class="px-4 py-2 text-end">{{ __('Days off') }}</th>
                                    <th scope="col" class="px-4 py-2 text-end">{{ __('Salary') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($this->salaries as $row)
                                    <tr wire:key="sal-{{ $row['worker']->id }}">
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('labour.workers.show', $row['worker']) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $row['worker']->displayName() }}</a>
                                            @if ($row['posted'])
                                                <p class="text-xs text-emerald-700">{{ __('Posted') }} · <span dir="ltr">{{ $row['posted']->entry->number }}</span></p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-end">
                                            @if ($row['rate'])
                                                <x-ui.money :amount="$row['rate']" />
                                            @else
                                                <a href="{{ route('labour.rates') }}" wire:navigate class="text-amber-700 hover:underline">{{ __('Not set') }}</a>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-end text-stone-600">
                                            @if ($row['absent'] === 0 && $row['half'] === 0)
                                                —
                                            @else
                                                {{ __('Absent :absent, half :half', ['absent' => $row['absent'], 'half' => $row['half']]) }}
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-end font-medium text-stone-900">
                                            @if ($row['salary'])
                                                <x-ui.money :amount="$row['salary']" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($canRecord)
                        <div class="flex flex-col gap-2 border-t border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-stone-500">
                                @if ($monthOpen)
                                    {{ __('This month is not over yet: days after today count as present.') }}
                                @else
                                    {{ __('Once posted, the attendance of the month is locked. Reverse a salary to change it.') }}
                                @endif
                            </p>
                            <x-ui.button icon="calendar-check-2" wire:click="postSalaries" wire:confirm="{{ __('Post the salaries of :month? Salaries already posted are skipped.', ['month' => $monthLabel]) }}" loading="postSalaries">{{ __('Post salaries for :month', ['month' => $monthLabel]) }}</x-ui.button>
                        </div>
                    @endif
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
