<?php

namespace App\Livewire\Reports;

use App\Accounting\LedgerQueries;
use App\Enums\Permission;
use App\Models\LedgerAccount;
use App\Models\TreasuryAccount;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * All ledger balances on a date. Total debits must equal total credits; the report says so
 * plainly so anyone can see the books are in order.
 */
class TrialBalance extends Component
{
    #[Url(as: 'date')]
    public string $asOf = '';

    public function mount(): void
    {
        $this->authorize(Permission::FinancialReportsView->value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->asOf)) {
            $this->asOf = now()->format('Y-m-d');
        }
    }

    /**
     * @return array{rows: Collection<int, array{account: LedgerAccount, debit: Money, credit: Money}>, total_debit: Money, total_credit: Money}|null
     */
    #[Computed]
    public function report(): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->asOf)) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $this->asOf);
        if ($date === null || $date->format('Y-m-d') !== $this->asOf) {
            return null;
        }

        return app(LedgerQueries::class)->trialBalance($date->startOfDay());
    }

    /**
     * Cash book links for accounts that are cash/bank accounts.
     *
     * @return array<int, int> ledger_account_id => treasury_account_id
     */
    #[Computed]
    public function treasuryLinks(): array
    {
        return TreasuryAccount::query()->pluck('id', 'ledger_account_id')->all();
    }

    public function render(): View
    {
        return view('livewire.reports.trial-balance')->title(__('Trial balance'));
    }
}
