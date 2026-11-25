{{-- Import --}}
<x-ui.card>
    <h2 class="text-base font-semibold text-stone-900">{{ __('Import from Excel') }}</h2>
    <p class="mt-1 text-sm text-stone-600">{{ __('For the first day: download a template, fill it in Excel, and upload it. Every row is checked first, and nothing is saved unless all rows are correct.') }}</p>

    @if ($importType === '')
        <ol class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($importers as $key => $importer)
                <li wire:key="importer-{{ $key }}" class="flex flex-col rounded-[var(--radius-control)] p-4 ring-1 ring-stone-200">
                    <p class="font-medium text-stone-900"><span class="tabular text-stone-400">{{ $loop->iteration }}.</span> {{ $importer->title() }}</p>
                    <p class="mt-1 flex-1 text-sm text-stone-600">{{ $importer->description() }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="file-down" :href="route('data.template', $key)">{{ __('Template') }}</x-ui.button>
                        <x-ui.button size="sm" icon="file-up" wire:click="chooseImport('{{ $key }}')">{{ __('Upload') }}</x-ui.button>
                    </div>
                </li>
            @endforeach
        </ol>
    @else
        @php($importer = $importers[$importType])
        <div class="mt-4 rounded-[var(--radius-control)] bg-stone-50 p-4 ring-1 ring-stone-200">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-medium text-stone-900">{{ __('Import: :what', ['what' => $importer->title()]) }}</p>
                <div class="flex gap-2">
                    <x-ui.button size="sm" variant="ghost" icon="file-down" :href="route('data.template', $importType)">{{ __('Template') }}</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" wire:click="cancelImport">{{ __('Back') }}</x-ui.button>
                </div>
            </div>

            <x-ui.field :label="__('Filled-in file (.xlsx or .csv)')" for="import-file" error="file" class="mt-3">
                <input id="import-file" type="file" wire:model="file" accept=".xlsx,.csv" class="block w-full text-sm text-stone-700 file:me-3 file:rounded-[var(--radius-control)] file:border-0 file:bg-brand-600 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-brand-700">
            </x-ui.field>
            <p wire:loading wire:target="file,import" class="mt-2 text-sm text-stone-600" role="status">{{ __('Checking the rows…') }}</p>

            @if ($report)
                <div @class([
                    'mt-4 rounded-[var(--radius-control)] px-3 py-2 text-sm ring-1',
                    'bg-emerald-50 text-emerald-900 ring-emerald-200' => $report['failed'] === 0,
                    'bg-red-50 text-red-800 ring-red-200' => $report['failed'] > 0,
                ]) role="status">
                    @if ($report['saved'])
                        {{ __(':count rows imported.', ['count' => $report['ok']]) }}
                    @elseif ($report['failed'] === 0)
                        {{ __('All :count rows are correct. Nothing is saved yet.', ['count' => $report['ok']]) }}
                    @else
                        {{ __(':failed of :total rows have a problem. Fix them in Excel and upload the file again. Nothing was saved.', ['failed' => $report['failed'], 'total' => $report['ok'] + $report['failed']]) }}
                    @endif
                </div>

                <div class="mt-3 max-h-96 overflow-auto rounded-[var(--radius-control)] bg-surface ring-1 ring-stone-200">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-stone-100 text-start text-xs text-stone-600">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ __('Row') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ __('What') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ __('Check') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($report['rows'] as $row)
                                <tr wire:key="report-{{ $row['line'] }}" @class(['bg-red-50/50' => $row['errors'] !== []])>
                                    <td class="px-3 py-1.5 tabular text-stone-500" dir="ltr">{{ $row['line'] }}</td>
                                    <td class="px-3 py-1.5"><bdi>{{ $row['summary'] }}</bdi></td>
                                    <td class="px-3 py-1.5">
                                        @if ($row['errors'] === [])
                                            <span class="text-emerald-700">{{ __('OK') }}</span>
                                        @else
                                            <ul class="text-red-700">
                                                @foreach ($row['errors'] as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($report['failed'] === 0 && ! $report['saved'])
                    <x-ui.button class="mt-4" icon="check" wire:click="import" loading="import">{{ __('Import :count rows', ['count' => $report['ok']]) }}</x-ui.button>
                @endif
            @endif
        </div>
    @endif
</x-ui.card>

{{-- Export --}}
<x-ui.card class="mt-6">
    <h2 class="text-base font-semibold text-stone-900">{{ __('Export to Excel') }}</h2>
    <p class="mt-1 text-sm text-stone-600">{{ __('Download your data to keep a copy or work with it in Excel. Each list also has an "Excel" button on its own page.') }}</p>
    <div class="mt-4 flex flex-wrap gap-2">
        <x-ui.button icon="file-spreadsheet" :href="route('data.export', 'all')">{{ __('Download all data') }}</x-ui.button>
        @foreach ($exports as $key => $label)
            <x-ui.button size="sm" variant="secondary" :href="route('data.export', $key)">{{ $label }}</x-ui.button>
        @endforeach
    </div>
</x-ui.card>
