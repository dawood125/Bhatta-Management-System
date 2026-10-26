@php
    $diskLabel = fn (?string $disk) => match ($disk) {
        'backups' => __('This server'),
        'google_drive' => __('Google Drive'),
        default => (string) $disk,
    };
    $size = fn (?int $bytes) => $bytes === null ? '—' : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
@endphp

<div>
    <x-ui.page-header :title="__('Backups')" :description="__('Every night at 2:00 the whole database and the uploaded files are saved. Now and then, download a copy and keep it somewhere safe, away from the server.')">
        <x-slot:actions>
            @if ($drillConfigured)
                <x-ui.button variant="secondary" icon="database-check" wire:click="testRestore" loading="testRestore">{{ __('Test a restore') }}</x-ui.button>
            @endif
            <x-ui.button icon="database-backup" wire:click="backUpNow" loading="backUpNow">{{ __('Back up now') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <p wire:loading wire:target="backUpNow,testRestore" class="mb-4 text-sm text-stone-600" role="status">{{ __('Working… this can take a minute. Keep this page open.') }}</p>

    {{-- Health --}}
    <div @class([
        'mb-6 flex items-start gap-3 rounded-[var(--radius-card)] px-4 py-3 text-sm ring-1',
        'bg-emerald-50 text-emerald-900 ring-emerald-200' => $healthy,
        'bg-red-50 text-red-900 ring-red-200' => ! $healthy,
    ])>
        @if ($healthy)
            <x-lucide-shield-check class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{!! __('Backups are up to date. The newest is from :date (:ago), :size.', [
                'date' => '<bdi class="tabular" dir="ltr">'.e($newest['date']->format('d-m-Y H:i')).'</bdi>',
                'ago' => e($newest['date']->diffForHumans()),
                'size' => '<bdi class="tabular" dir="ltr">'.e($size($newest['size'])).'</bdi>',
            ]) !!}</p>
        @else
            <x-lucide-shield-alert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-medium">
                    @if ($newest === null)
                        {{ __('There are no backups yet.') }}
                    @else
                        {!! __('No backup in the last day. The newest is from :date (:ago).', ['date' => '<bdi class="tabular" dir="ltr">'.e($newest['date']->format('d-m-Y H:i')).'</bdi>', 'ago' => e($newest['date']->diffForHumans())]) !!}
                    @endif
                </p>
                @if ($unreachable !== [])
                    <p>{{ __('Cannot reach: :disks.', ['disks' => implode(', ', array_map($diskLabel, $unreachable))]) }}</p>
                @endif
                <p>{{ __('Press “Back up now”. If it fails, tell your developer: the nightly backup needs the server’s scheduler.') }}</p>
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="min-w-0 space-y-6">
            {{-- The backups --}}
            <section>
                <h2 class="mb-2 text-base font-semibold text-stone-900">{{ __('Backups kept') }}</h2>
                @if ($this->backups->isEmpty())
                    <x-ui.card :padding="false">
                        <x-ui.empty-state icon="database-backup" :title="__('No backups yet')" :description="__('The first one is made tonight, or now with “Back up now”.')" />
                    </x-ui.card>
                @else
                    <x-ui.table>
                        <x-slot:head>
                            <x-ui.th>{{ __('Made') }}</x-ui.th>
                            <x-ui.th align="end">{{ __('Size') }}</x-ui.th>
                            <x-ui.th>{{ __('Kept on') }}</x-ui.th>
                            <x-ui.th align="end"><span class="sr-only">{{ __('Download') }}</span></x-ui.th>
                        </x-slot:head>
                        @foreach ($this->backups as $backup)
                            <tr wire:key="backup-{{ $backup['disk'] }}-{{ $backup['file'] }}">
                                <x-ui.td class="whitespace-nowrap">
                                    <span class="tabular font-medium text-stone-900" dir="ltr">{{ $backup['date']->format('d-m-Y H:i') }}</span>
                                    <span class="block text-xs text-stone-500">{{ $backup['date']->diffForHumans() }}</span>
                                </x-ui.td>
                                <x-ui.td align="end" class="tabular whitespace-nowrap" dir="ltr">{{ $size($backup['size']) }}</x-ui.td>
                                <x-ui.td>{{ $diskLabel($backup['disk']) }}</x-ui.td>
                                <x-ui.td align="end">
                                    <x-ui.button size="sm" variant="ghost" icon="download" :href="route('backups.download', ['disk' => $backup['disk'], 'file' => $backup['file']])">{{ __('Download') }}</x-ui.button>
                                </x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </section>

            {{-- History --}}
            <section>
                <h2 class="mb-2 text-base font-semibold text-stone-900">{{ __('Recent backups and restore tests') }}</h2>
                @if ($this->runs->isEmpty())
                    <x-ui.card><p class="text-sm text-stone-500">{{ __('Nothing yet.') }}</p></x-ui.card>
                @else
                    <x-ui.table>
                        <x-slot:head>
                            <x-ui.th>{{ __('When') }}</x-ui.th>
                            <x-ui.th>{{ __('What') }}</x-ui.th>
                            <x-ui.th>{{ __('Result') }}</x-ui.th>
                        </x-slot:head>
                        @foreach ($this->runs as $run)
                            <tr wire:key="run-{{ $run->id }}">
                                <x-ui.td class="whitespace-nowrap"><span class="tabular" dir="ltr">{{ $run->created_at->format('d-m-Y H:i') }}</span></x-ui.td>
                                <x-ui.td>
                                    @if ($run->kind === \App\Models\BackupRun::DRILL)
                                        {{ __('Restore test') }}
                                    @elseif ($run->kind === \App\Models\BackupRun::COPY)
                                        {{ __('Copy to Google Drive') }}
                                    @elseif ($run->user)
                                        {{ __('Backup by :name', ['name' => $run->user->name]) }}
                                    @else
                                        {{ __('Nightly backup') }}
                                    @endif
                                    @if ($run->disk && ! in_array($run->kind, [\App\Models\BackupRun::DRILL, \App\Models\BackupRun::COPY], true))
                                        <span class="text-stone-500">· {{ $diskLabel($run->disk) }}</span>
                                    @endif
                                </x-ui.td>
                                <x-ui.td>
                                    @if ($run->succeeded())
                                        <x-ui.badge tone="success" icon="check">{{ __('Done') }}</x-ui.badge>
                                        <span class="text-sm text-stone-600">
                                            @if ($run->kind === \App\Models\BackupRun::DRILL)
                                                {{ __(':tables tables and :rows rows came back the same; the books are consistent.', ['tables' => $run->details['tables'] ?? 0, 'rows' => number_format((int) ($run->details['rows'] ?? 0))]) }}
                                            @else
                                                <span class="tabular" dir="ltr">{{ $size($run->size_bytes) }}</span>
                                            @endif
                                        </span>
                                    @else
                                        <x-ui.badge tone="danger" icon="x">{{ __('Failed') }}</x-ui.badge>
                                        <span class="block text-sm text-red-700"><bdi>{{ $run->message }}</bdi></span>
                                    @endif
                                </x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </section>
        </div>

        <div class="h-fit min-w-0 space-y-6">
        {{-- Copies on Google Drive --}}
        <x-ui.card class="space-y-3">
            <div class="flex items-center gap-2">
                <x-lucide-hard-drive-upload class="size-5 text-stone-500" aria-hidden="true" />
                <h2 class="text-base font-semibold text-stone-900">{{ __('Copies on Google Drive') }}</h2>
            </div>

            @if (! $driveAvailable)
                <p class="text-sm text-stone-600">{{ __('Not available on this server yet: your developer adds the Google sign-in details once (deployment guide, “Google Drive copies”).') }}</p>
            @elseif ($driveCode)
                <div wire:poll.5s="checkGoogleDrive" class="space-y-3 text-sm">
                    <p class="text-stone-700">{{ __('On a phone or computer:') }}</p>
                    <ol class="list-decimal space-y-1 ps-5 text-stone-700">
                        <li>{!! __('Open :url', ['url' => '<a href="'.e($driveCode['url']).'" target="_blank" rel="noopener" class="font-medium text-brand-700 underline" dir="ltr">'.e(preg_replace('#^https?://(www\.)?#', '', $driveCode['url'])).'</a>']) !!}</li>
                        <li>{{ __('Enter this code:') }}</li>
                    </ol>
                    <p class="rounded-[var(--radius-control)] bg-stone-100 px-3 py-2 text-center text-2xl font-semibold tracking-widest text-stone-900 tabular" dir="ltr">{{ $driveCode['user_code'] }}</p>
                    <p class="text-stone-700">{{ __('Choose the Google account for the backups and allow access. This page notices by itself.') }}</p>
                    <p class="flex items-center gap-2 text-xs text-stone-500"><x-lucide-loader-circle class="size-4 animate-spin" aria-hidden="true" /> {{ __('Waiting for Google…') }}</p>
                    <x-ui.button size="sm" variant="ghost" wire:click="cancelGoogleDrive">{{ __('Cancel') }}</x-ui.button>
                </div>
            @elseif ($drive->hasGoogleDrive())
                <p class="text-sm text-stone-700">
                    {{ __('Connected to :account. Every backup is copied to the folder “:folder”; the newest :keep copies are kept.', ['account' => $drive->google_drive_email ?: __('a Google account'), 'folder' => config('app.name').' backups', 'keep' => config('services.google_drive.keep')]) }}
                </p>
                @if ($lastCopy)
                    <p @class(['text-sm', 'text-stone-600' => $lastCopy->succeeded(), 'text-red-700' => ! $lastCopy->succeeded()])>
                        {{ $lastCopy->succeeded()
                            ? __('Last copy: :date.', ['date' => $lastCopy->created_at->format('d-m-Y H:i')])
                            : __('The last copy failed (:date): :error', ['date' => $lastCopy->created_at->format('d-m-Y H:i'), 'error' => $lastCopy->message]) }}
                    </p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="secondary" icon="upload" wire:click="copyNewestNow" loading="copyNewestNow">{{ __('Copy the newest now') }}</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="disconnectGoogleDrive" wire:confirm="{{ __('Stop copying backups to Google Drive? The copies already there stay in your Drive.') }}">{{ __('Disconnect') }}</x-ui.button>
                </div>
            @else
                <p class="text-sm text-stone-600">{{ __('Keep a copy of every backup in your Google Drive, safe if this server is ever lost. Only this app’s own backup folder can be seen by it, nothing else in your Drive.') }}</p>
                <x-ui.button size="sm" icon="hard-drive-upload" wire:click="connectGoogleDrive" loading="connectGoogleDrive">{{ __('Connect Google Drive') }}</x-ui.button>
            @endif
        </x-ui.card>

        {{-- How backups are set up --}}
        <x-ui.card class="min-w-0">
            <h2 class="text-base font-semibold text-stone-900">{{ __('How backups are kept') }}</h2>
            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="text-xs font-medium text-stone-500">{{ __('Kept on') }}</dt>
                    <dd class="text-stone-900">{{ implode(', ', array_map($diskLabel, $catalog->disks())) }}@if ($drive->hasGoogleDrive()), {{ __('Google Drive') }}@endif</dd>
                    @if ($catalog->disks() === ['backups'] && ! $drive->hasGoogleDrive())
                        <dd class="mt-0.5 text-xs text-amber-800">{{ __('Only on this server. If the server is lost, so are these backups: connect Google Drive, or download a copy every week.') }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-xs font-medium text-stone-500">{{ __('Password protected') }}</dt>
                    <dd @class(['text-stone-900' => $catalog->isEncrypted(), 'text-amber-800' => ! $catalog->isEncrypted()])>
                        {{ $catalog->isEncrypted() ? __('Yes. Keep the backup password safe: without it a backup cannot be opened.') : __('No. Ask your developer to set a backup password.') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-stone-500">{{ __('Email if a backup fails') }}</dt>
                    <dd class="text-stone-900"><bdi>{{ $catalog->alertEmails() === [] ? __('Not set') : implode(', ', $catalog->alertEmails()) }}</bdi></dd>
                    @can(\App\Enums\Permission::KilnProfileManage->value)
                        <dd class="mt-0.5"><a href="{{ route('settings.email') }}" wire:navigate class="text-xs font-medium text-brand-700 hover:underline">{{ __('Change in Settings → Email') }}</a></dd>
                    @endcan
                </div>
                <div>
                    <dt class="text-xs font-medium text-stone-500">{{ __('How long') }}</dt>
                    <dd class="text-stone-900">{{ __('Every backup for 7 days, then one a day for 30 days, one a week for 12 weeks, one a month for a year, and one a year for 5 years.') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-stone-500">{{ __('Restore test') }}</dt>
                    <dd class="text-stone-900">
                        @if (! $drillConfigured)
                            <span class="text-amber-800">{{ __('Not set up. Ask your developer to set up the monthly restore test.') }}</span>
                        @elseif ($lastDrill === null)
                            {{ __('Every month on the 1st. None has run yet.') }}
                        @else
                            {{ __('Every month on the 1st. Last: :date, :result.', ['date' => $lastDrill->created_at->format('d-m-Y'), 'result' => $lastDrill->succeeded() ? __('passed') : __('failed')]) }}
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>
        </div>
    </div>
</div>
