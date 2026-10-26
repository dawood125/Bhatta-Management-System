{{-- Pages of a long statement (StatementPager): which rows are shown, and older/newer pages. --}}
@props(['statement'])

@if ($statement['pages'] > 1)
    @php
        $perPage = \App\Accounting\StatementPager::perPage();
        $first = ($statement['page'] - 1) * $perPage + 1;
        $last = min($statement['count'], $statement['page'] * $perPage);
    @endphp
    <nav class="mt-3 flex flex-col gap-2 text-sm text-stone-600 sm:flex-row sm:items-center sm:justify-between print:hidden" aria-label="{{ __('Statement pages') }}">
        <p>
            {{ __('Rows :first–:last of :count', ['first' => number_format($first), 'last' => number_format($last), 'count' => number_format($statement['count'])]) }}
            <span class="text-stone-400">·</span>
            {{ __('Page :page of :pages', ['page' => $statement['page'], 'pages' => $statement['pages']]) }}
        </p>
        <div class="flex flex-wrap gap-2">
            <x-ui.button size="sm" variant="secondary" wire:click="showStatementPage(1)" :disabled="$statement['page'] === 1">{{ __('Oldest') }}</x-ui.button>
            <x-ui.button size="sm" variant="secondary" wire:click="showStatementPage({{ $statement['page'] - 1 }})" :disabled="$statement['page'] === 1">{{ __('Older') }}</x-ui.button>
            <x-ui.button size="sm" variant="secondary" wire:click="showStatementPage({{ $statement['page'] + 1 }})" :disabled="$statement['page'] === $statement['pages']">{{ __('Newer') }}</x-ui.button>
            <x-ui.button size="sm" variant="secondary" wire:click="showStatementPage({{ $statement['pages'] }})" :disabled="$statement['page'] === $statement['pages']">{{ __('Newest') }}</x-ui.button>
        </div>
    </nav>
@endif
