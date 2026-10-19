<?php

namespace App\Livewire\Parties;

use App\Accounting\LedgerQueries;
use App\Enums\Permission;
use App\Models\JournalLine;
use App\Models\Party;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A party's statement (khata): balance before the period, every entry with a running balance,
 * and the closing balance. Printable.
 */
class PartyStatement extends Component
{
    public Party $party;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(Party $party): void
    {
        $this->authorize(Permission::PartiesView->value);
        $this->party = $party;
        $this->from = $this->validDate($this->from) ?? $party->opening_date->format('Y-m-d');
        $this->to = $this->validDate($this->to) ?? now()->format('Y-m-d');
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->to] = match ($range) {
            'this_month' => [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            default => [$this->party->opening_date->format('Y-m-d'), now()->format('Y-m-d')],
        };
    }

    /**
     * @return array{opening: Money, rows: list<array{line: JournalLine, debit: Money, credit: Money, balance: Money}>, closing: Money, total_debit: Money, total_credit: Money}|null
     */
    #[Computed]
    public function statement(): ?array
    {
        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);

        if ($from === null || $to === null || $from > $to) {
            return null;
        }

        return app(LedgerQueries::class)->partyStatement(
            $this->party,
            Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $to)->startOfDay(),
        );
    }

    #[Computed]
    public function currentBalance(): Money
    {
        return $this->party->balance();
    }

    public function render(): View
    {
        $this->party->loadMissing(['area', 'salesAgent']);

        return view('livewire.parties.party-statement', [
            'roles' => $this->party->roles(),
        ])->title(__('Statement').' · '.$this->party->displayName());
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
