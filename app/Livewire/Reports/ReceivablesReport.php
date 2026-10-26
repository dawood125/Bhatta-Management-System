<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Models\Party;
use App\Reports\ReceivablesAgeing;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Who owes the kiln now, and for how long (by the age of their unpaid sales).
 *
 * @property-read array{rows: list<array{party: Party, balance: Money, buckets: array<string, Money>, other: Money}>, totals: array{balance: Money, buckets: array<string, Money>, other: Money}} $report Livewire computed property
 */
class ReceivablesReport extends Component
{
    public function mount(): void
    {
        $this->authorize(Permission::PartiesView->value);
    }

    /**
     * @return array{rows: list<array{party: Party, balance: Money, buckets: array<string, Money>, other: Money}>, totals: array{balance: Money, buckets: array<string, Money>, other: Money}}
     */
    #[Computed]
    public function report(): array
    {
        return app(ReceivablesAgeing::class)->now();
    }

    public function render(): View
    {
        return view('livewire.reports.receivables-report')->title(__('Who owes us'));
    }
}
