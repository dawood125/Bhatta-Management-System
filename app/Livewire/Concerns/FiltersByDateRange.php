<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * A "from – to" date filter kept in the URL, with quick ranges. Defaults to this month.
 * For paginated lists (use together with WithPagination).
 */
trait FiltersByDateRange
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    protected function initDateRange(): void
    {
        $this->from = $this->validDate($this->from) ?? now()->startOfMonth()->format('Y-m-d');
        $this->to = $this->validDate($this->to) ?? now()->format('Y-m-d');
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->to] = match ($range) {
            'today' => [now()->format('Y-m-d'), now()->format('Y-m-d')],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            'this_year' => [now()->startOfYear()->format('Y-m-d'), now()->format('Y-m-d')],
            default => [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
        };

        $this->resetPage();
    }

    /** Provided by Livewire's WithPagination, which every user of this trait also uses. */
    abstract public function resetPage($pageName = 'page');

    /**
     * The chosen period, or null while the dates are invalid or reversed.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    protected function dateRange(): ?array
    {
        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);

        if ($from === null || $to === null || $from > $to) {
            return null;
        }

        return [Carbon::createFromFormat('Y-m-d', $from)->startOfDay(), Carbon::createFromFormat('Y-m-d', $to)->startOfDay()];
    }

    private function validDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }
}
