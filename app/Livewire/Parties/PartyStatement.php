<?php

namespace App\Livewire\Parties;

use App\Accounting\LedgerQueries;
use App\Actions\Sales\WriteOffBalance;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Livewire\Concerns\PagesStatement;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A party's statement (khata): balance before the period, every entry with a running balance,
 * and the closing balance. Printable, and as a PDF. The owner can write off what a customer will
 * never pay (bad debt).
 *
 * @property-read Money $currentBalance Livewire computed property
 */
class PartyStatement extends Component
{
    use PagesStatement, RunsActions;

    public Party $party;

    public bool $showWriteOff = false;

    /** @var array<string, string> */
    public array $writeOffForm = [];

    public string $formKey = '';

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
        $this->statementPage = '';
        [$this->from, $this->to] = match ($range) {
            'this_month' => [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            default => [$this->party->opening_date->format('Y-m-d'), now()->format('Y-m-d')],
        };
    }

    /**
     * @return array{opening: Money, brought_forward: Money, rows: list<array{line: JournalLine, debit: Money, credit: Money, balance: Money}>, closing: Money, total_debit: Money, total_credit: Money, page: int, pages: int, count: int}|null
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
            $this->statementPageNumber(),
            $this->statementPerPage(),
        );
    }

    #[Computed]
    public function currentBalance(): Money
    {
        return $this->party->balance();
    }

    public function openWriteOff(): void
    {
        $this->authorize(Permission::SalesWriteOff->value);
        $this->resetErrorBag();
        $this->writeOffForm = [
            'entry_date' => now()->format('Y-m-d'),
            'amount' => $this->currentBalance->isPositive() ? $this->currentBalance->toDecimal() : '',
            'reason' => '',
        ];
        $this->formKey = (string) Str::uuid();
        $this->showWriteOff = true;
    }

    public function writeOff(WriteOffBalance $writeOff): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $entry = $this->runAction(fn () => $writeOff->handle($actor, $this->party, $this->writeOffForm, $this->formKey), 'writeOffForm');

        $this->showWriteOff = false;
        unset($this->statement, $this->currentBalance);
        $this->toast(__(':number recorded.', ['number' => $entry->number]));
    }

    public function render(): View
    {
        $this->party->loadMissing(['area', 'salesAgent']);
        $roles = $this->party->roles();

        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.parties.party-statement', [
            'roles' => $roles,
            'canWriteOff' => $actor->can(Permission::SalesWriteOff->value) && in_array(PartyRole::Customer, $roles, true) && $this->currentBalance->isPositive(),
            'pdfUrl' => route('parties.statement.pdf', ['party' => $this->party, 'from' => $this->validDate($this->from), 'to' => $this->validDate($this->to)]),
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
