<?php

namespace App\Livewire\Concerns;

/**
 * The "from – to" filter (FiltersByDateRange) for reports, which are not paginated.
 */
trait FiltersReportPeriod
{
    use FiltersByDateRange;

    /**
     * Reports have no pages: changing the period has nothing to reset.
     *
     * @param  string  $pageName
     */
    public function resetPage($pageName = 'page'): void {}
}
