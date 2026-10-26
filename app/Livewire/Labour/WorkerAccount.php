<?php

namespace App\Livewire\Labour;

use App\Accounting\LedgerQueries;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Labour\RecordWorkEntry;
use App\Actions\Labour\RecordWorkerMoney;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Livewire\Concerns\PagesStatement;
use App\Livewire\Concerns\RunsActions;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LabourRate;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A worker's account (khata): what they earned, the advances, deductions and payments, with the
 * running balance. Work, advances and deductions are recorded from here. Printable.
 *
 * @property-read array{opening: Money, brought_forward: Money, rows: list<array{line: JournalLine, earned: Money, taken: Money, balance: Money}>, closing: Money, total_earned: Money, total_taken: Money, page: int, pages: int, count: int}|null $statement Livewire computed property
 * @property-read array<int, WorkEntry> $workEntries Livewire computed property
 * @property-read Money $currentBalance Livewire computed property
 * @property-read LabourRate|null $currentRate Livewire computed property
 * @property-read LabourRate|null $workRate Livewire computed property
 * @property-read Money|null $workAmount Livewire computed property
 * @property-read array<int, string> $accountOptions Livewire computed property
 * @property-read Money|null $accountBalance Livewire computed property
 * @property-read JournalEntry|null $reversing Livewire computed property
 */
class WorkerAccount extends Component
{
    use PagesStatement, RunsActions;

    public Worker $worker;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Which dialog is open: work | money | deduction | reverse (empty: none). Set by the open methods only. */
    #[Locked]
    public string $dialog = '';

    public bool $showDialog = false;

    /** @var array<string, string> */
    public array $form = [];

    public string $formKey = '';

    #[Locked]
    public ?int $reversingId = null;

    public function mount(Worker $worker): void
    {
        $this->authorize(Permission::LabourView->value);
        $this->worker = $worker;
        $this->from = $this->validDate($this->from) ?? now()->startOfMonth()->format('Y-m-d');
        $this->to = $this->validDate($this->to) ?? now()->format('Y-m-d');
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'form.')) {
            $this->resetErrorBag($property);
        }
    }

    public function setRange(string $range): void
    {
        $this->statementPage = '';
        [$this->from, $this->to] = match ($range) {
            'this_week' => [now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'), now()->format('Y-m-d')],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            'all' => [$this->firstDate(), now()->format('Y-m-d')],
            default => [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
        };
    }

    /**
     * @return array{opening: Money, brought_forward: Money, rows: list<array{line: JournalLine, earned: Money, taken: Money, balance: Money}>, closing: Money, total_earned: Money, total_taken: Money, page: int, pages: int, count: int}|null
     */
    #[Computed]
    public function statement(): ?array
    {
        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);

        if ($from === null || $to === null || $from > $to) {
            return null;
        }

        return app(WorkerLedger::class)->statement(
            $this->worker,
            Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $to)->startOfDay(),
            $this->statementPageNumber(),
            $this->statementPerPage(),
        );
    }

    /**
     * Work details (bricks, days, rate) of the wages entries in the statement.
     *
     * @return array<int, WorkEntry>
     */
    #[Computed]
    public function workEntries(): array
    {
        $entryIds = collect($this->statement['rows'] ?? [])->map(fn (array $row) => $row['line']->journal_entry_id)->unique()->values();

        return WorkEntry::query()->with('productionEntry')->whereIn('journal_entry_id', $entryIds)->get()->keyBy('journal_entry_id')->all();
    }

    #[Computed]
    public function currentBalance(): Money
    {
        return $this->worker->balance();
    }

    #[Computed]
    public function currentRate(): ?LabourRate
    {
        return app(WorkerLedger::class)->rateFor($this->worker, now());
    }

    /** The rate on the date chosen in the work form. */
    #[Computed]
    public function workRate(): ?LabourRate
    {
        $date = $this->validDate((string) ($this->form['work_date'] ?? ''));

        return $date !== null ? app(WorkerLedger::class)->rateFor($this->worker, Carbon::createFromFormat('Y-m-d', $date)->startOfDay()) : null;
    }

    /** What the work form will earn, while its figures are valid. */
    #[Computed]
    public function workAmount(): ?Money
    {
        $basis = $this->worker->category->basis;
        $rate = $this->workRate;
        if ($rate === null || ! in_array($basis, [PayBasis::PerThousand, PayBasis::PerDay], true)) {
            return null;
        }

        try {
            $quantity = Quantity::fromInput((string) ($this->form['quantity'] ?? ''), $basis->decimals());
        } catch (InvalidArgumentException) {
            return null;
        }

        return $quantity->isPositive() ? $rate->rate->timesQuantity($quantity->toDecimal(), $basis === PayBasis::PerThousand ? 1000 : 1) : null;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return TreasuryAccount::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (TreasuryAccount $a) => [$a->id => $a->displayName()])->all();
    }

    #[Computed]
    public function accountBalance(): ?Money
    {
        $account = ctype_digit((string) ($this->form['account_id'] ?? '')) ? TreasuryAccount::query()->with('ledgerAccount')->find((int) $this->form['account_id']) : null;

        return $account !== null ? app(LedgerQueries::class)->balance($account->ledgerAccount) : null;
    }

    public function openWork(): void
    {
        $this->authorize(Permission::LabourRecord->value);
        $this->open('work', ['work_date' => now()->format('Y-m-d'), 'quantity' => '', 'amount' => '', 'description' => '']);
    }

    public function openMoney(string $direction = RecordWorkerMoney::GIVE): void
    {
        $this->authorize(Permission::LabourPay->value);
        $this->open('money', [
            'direction' => $direction === RecordWorkerMoney::RECEIVE ? RecordWorkerMoney::RECEIVE : RecordWorkerMoney::GIVE,
            'account_id' => (string) (array_key_first($this->accountOptions) ?? ''),
            'entry_date' => now()->format('Y-m-d'),
            'amount' => '',
            'memo' => '',
        ]);
    }

    public function openDeduction(): void
    {
        $this->authorize(Permission::LabourRecord->value);
        $this->open('deduction', ['entry_date' => now()->format('Y-m-d'), 'amount' => '', 'memo' => '']);
    }

    public function confirmReverse(int $entryId): void
    {
        $this->authorize(Permission::TransactionsReverse->value);
        $entry = JournalEntry::query()
            ->whereKey($entryId)
            ->whereHas('lines', fn ($q) => $q->where('worker_id', $this->worker->id))
            ->firstOrFail();
        $this->reversingId = $entry->id;
        $this->open('reverse', ['reason' => '']);
    }

    public function save(): void
    {
        $actor = $this->actor();
        $input = ['worker_id' => (string) $this->worker->id] + $this->form;

        $message = match ($this->dialog) {
            'work' => (function () use ($actor, $input): string {
                $work = $this->runAction(fn () => app(RecordWorkEntry::class)->handle($actor, $input, $this->formKey), 'form');

                return __('Wages :number recorded: :amount.', ['number' => $work->entry->number, 'amount' => $work->amount->formatWithCurrency()]);
            })(),
            'money' => (function () use ($actor, $input): string {
                $entry = $this->runAction(fn () => app(RecordWorkerMoney::class)->advance($actor, $input, $this->formKey), 'form');

                return __(':number recorded.', ['number' => $entry->number]);
            })(),
            'deduction' => (function () use ($actor, $input): string {
                $entry = $this->runAction(fn () => app(RecordWorkerMoney::class)->deduction($actor, $input, $this->formKey), 'form');

                return __(':number recorded.', ['number' => $entry->number]);
            })(),
            'reverse' => (function () use ($actor): string {
                $entry = JournalEntry::query()->findOrFail((int) $this->reversingId);
                $reversal = $this->runAction(fn () => app(ReverseEntry::class)->handle($actor, $entry, $this->form, $this->formKey), 'form');

                return __(':number reversed by :reversal.', ['number' => $entry->number, 'reversal' => $reversal->number]);
            })(),
            default => '',
        };

        if ($message === '') {
            return;
        }

        $this->showDialog = false;
        $this->dialog = '';
        $this->toast($message);
        unset($this->statement, $this->workEntries, $this->currentBalance, $this->accountBalance);
    }

    #[Computed]
    public function reversing(): ?JournalEntry
    {
        return $this->reversingId ? JournalEntry::query()->find($this->reversingId) : null;
    }

    public function render(): View
    {
        $this->worker->loadMissing('category');
        $actor = $this->actor();

        return view('livewire.labour.worker-account', [
            'basis' => $this->worker->category->basis,
            'canRecord' => $actor->can(Permission::LabourRecord->value) && $this->worker->is_active,
            'canPay' => $actor->can(Permission::LabourPay->value) && $this->worker->is_active,
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
        ])->title(__('Account').' · '.$this->worker->displayName());
    }

    /**
     * @param  array<string, string>  $form
     */
    private function open(string $dialog, array $form): void
    {
        $this->resetErrorBag();
        $this->dialog = $dialog;
        $this->form = $form;
        $this->formKey = (string) Str::uuid();
        unset($this->workRate, $this->workAmount, $this->accountBalance);
        $this->showDialog = true;
    }

    /** The day of the worker's first entry (or today), for "All". */
    private function firstDate(): string
    {
        $first = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.worker_id', $this->worker->id)
            ->min('journal_entries.entry_date');

        return $first !== null ? Carbon::parse((string) $first)->format('Y-m-d') : now()->format('Y-m-d');
    }

    private function validDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
