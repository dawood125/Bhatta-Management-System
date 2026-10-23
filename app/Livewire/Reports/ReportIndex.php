<?php

namespace App\Livewire\Reports;

use App\Models\User;
use App\Reports\ReportCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * All reports in one place, grouped by area (only those the user may open).
 */
class ReportIndex extends Component
{
    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        abort_if(ReportCatalog::for($user) === [], 403);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.reports.report-index', ['groups' => ReportCatalog::for($user)])->title(__('Reports'));
    }
}
