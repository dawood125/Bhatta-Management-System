<?php

namespace App\Livewire\Accounts;

use App\Accounting\LedgerQueries;
use App\Enums\Permission;
use App\Models\TreasuryAccount;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statement of one cash/bank account for a period: opening balance, every movement with a
 * running balance, and the closing balance.
 */
class CashBook extends Component
{
    public TreasuryAccount $account;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(TreasuryAccount $account): void
    {
        $this->authorize(Permission::AccountsView->value);
        $this->account = $account->load('ledgerAccount');
        $this->from = $this->validDate($this->from) ?? now()->startOfMonth()->format('Y-m-d');
        $this->to = $this->validDate($this->to) ?? now()->format('Y-m-d');
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->to] = match ($range) {
            'today' => [now()->format('Y-m-d'), now()->format('Y-m-d')],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            'all' => [$this->account->opening_date->format('Y-m-d'), now()->format('Y-m-d')],
            default => [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
        };
    }

    /**
     * @return array{opening: Money, rows: list<array<string, mixed>>, closing: Money, total_in: Money, total_out: Money}|null
     */
    #[Computed]
    public function statement(): ?array
    {
        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);

        if ($from === null || $to === null || $from > $to) {
            return null;
        }

        $this->account->loadMissing('ledgerAccount');

        return app(LedgerQueries::class)->statement(
            $this->account->ledgerAccount,
            Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $to)->startOfDay(),
        );
    }

    public function render(): View
    {
        return view('livewire.accounts.cash-book')->title(__('Cash book').' · '.$this->account->displayName());
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
