<?php

namespace App\Livewire\Labour;

use App\Accounting\LedgerQueries;
use App\Actions\Labour\RecordSettlement;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Livewire\Concerns\RunsActions;
use App\Models\LabourCategory;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Worker;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pay workers for a period (e.g. the weekly Pathai settlement): for each worker the balance
 * brought forward, what they earned, the advances and deductions, and what is payable. The
 * amount to pay starts at what is owed and can be lowered; the rest stays on their account.
 *
 * @property-read list<array{worker: Worker, summary: array{brought_forward: Money, earned: Money, advances: Money, deductions: Money, payable: Money}, owed_now: Money}>|null $rows Livewire computed property
 * @property-read array<int, string> $categoryOptions Livewire computed property
 * @property-read array<int, string> $accountOptions Livewire computed property
 * @property-read Money|null $accountBalance Livewire computed property
 */
class SettlementForm extends Component
{
    use RunsActions;

    #[Url(except: '')]
    public string $category = '';

    public string $periodFrom = '';

    public string $periodTo = '';

    public string $settlementDate = '';

    public string $accountId = '';

    public string $notes = '';

    /** @var array<int|string, bool> worker id => included */
    public array $include = [];

    /** @var array<int|string, string> worker id => amount to pay */
    public array $pay = [];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::LabourPay->value);
        // A week ending today, the usual Pathai settlement.
        $this->periodFrom = now()->subDays(6)->format('Y-m-d');
        $this->periodTo = now()->format('Y-m-d');
        $this->settlementDate = now()->format('Y-m-d');
        $this->accountId = (string) (array_key_first($this->accountOptions) ?? '');
        $this->formKey = (string) Str::uuid();
        $this->fillDefaults();
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag($property);

        if (in_array($property, ['category', 'periodFrom', 'periodTo', 'settlementDate'], true)) {
            unset($this->rows);
            $this->fillDefaults();
        }
    }

    public function setPeriod(string $period): void
    {
        [$this->periodFrom, $this->periodTo] = match ($period) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            'this_month' => [now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            default => [now()->subDays(6)->format('Y-m-d'), now()->format('Y-m-d')],
        };
        unset($this->rows);
        $this->fillDefaults();
    }

    /**
     * Active workers (of the chosen category) with their figures for the period. Null while the
     * dates are invalid.
     *
     * @return list<array{worker: Worker, summary: array{brought_forward: Money, earned: Money, advances: Money, deductions: Money, payable: Money}, owed_now: Money}>|null
     */
    #[Computed]
    public function rows(): ?array
    {
        $from = $this->validDate($this->periodFrom);
        $to = $this->validDate($this->periodTo);
        $date = $this->validDate($this->settlementDate);
        if ($from === null || $to === null || $date === null || $from > $to || $date < $to) {
            return null;
        }

        $workers = Worker::query()
            ->with('category')
            ->where('is_active', true)
            ->when(ctype_digit($this->category), fn (Builder $q) => $q->where('labour_category_id', (int) $this->category))
            ->orderBy('name')
            ->get();

        $ids = $workers->pluck('id')->all();
        $ledger = app(WorkerLedger::class);
        $summary = $ledger->periodSummary($ids, Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay());
        $owedNow = $ledger->balances($ids, Carbon::parse($date)->startOfDay());

        return $workers->map(fn (Worker $worker) => [
            'worker' => $worker,
            'summary' => $summary[$worker->id],
            'owed_now' => $owedNow[$worker->id],
        ])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return LabourCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (LabourCategory $c) => [$c->id => $c->displayName()])->all();
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
        $account = ctype_digit($this->accountId) ? TreasuryAccount::query()->with('ledgerAccount')->find((int) $this->accountId) : null;

        return $account !== null ? app(LedgerQueries::class)->balance($account->ledgerAccount) : null;
    }

    public function save(RecordSettlement $recordSettlement): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        // Lines in the order shown, so an error lands on the right row.
        $lines = [];
        $rowIndex = [];
        foreach ($this->rows ?? [] as $index => $row) {
            $id = $row['worker']->id;
            if ($this->include[$id] ?? false) {
                $rowIndex[count($lines)] = $id;
                $lines[] = ['worker_id' => $id, 'paid' => $this->pay[$id] ?? ''];
            }
        }

        try {
            $settlement = $recordSettlement->handle($actor, [
                'period_from' => $this->periodFrom,
                'period_to' => $this->periodTo,
                'settlement_date' => $this->settlementDate,
                'account_id' => $this->accountId,
                'notes' => $this->notes,
                'lines' => $lines,
            ], $this->formKey);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $key => $errors) {
                $mapped = match (true) {
                    (bool) preg_match('/^lines\.(\d+)\.(paid|worker_id)$/', $key, $m) => 'pay.'.($rowIndex[(int) $m[1]] ?? 0),
                    $key === 'period_from' => 'periodFrom',
                    $key === 'period_to' => 'periodTo',
                    $key === 'settlement_date' => 'settlementDate',
                    $key === 'account_id', $key === 'amount' => 'accountId',
                    default => $key,
                };
                $messages[$mapped] = $errors;
            }

            $this->setErrorBag($messages);

            return;
        }

        session()->flash('toast', ['type' => 'success', 'message' => __('Settlement :number saved.', ['number' => $settlement->number])]);
        $this->redirectRoute('labour.settlements.show', ['settlement' => $settlement->id], navigate: true);
    }

    public function render(): View
    {
        $total = Money::zero();
        foreach ($this->rows ?? [] as $row) {
            $id = $row['worker']->id;
            if (($this->include[$id] ?? false) && preg_match('/^\d{1,13}(\.\d{1,2})?$/', trim($this->pay[$id] ?? ''))) {
                $total = $total->add(Money::fromRupees(trim($this->pay[$id])));
            }
        }

        return view('livewire.labour.settlement-form', ['total' => $total])->title(__('New settlement'));
    }

    /** Every worker with something owed (or anything in the period) is ticked, to be paid what is owed. */
    private function fillDefaults(): void
    {
        $this->include = [];
        $this->pay = [];

        foreach ($this->rows ?? [] as $row) {
            $id = $row['worker']->id;
            $owed = Money::fromPaisa(max(0, min($row['summary']['payable']->paisa, $row['owed_now']->paisa)));
            $active = ! $row['summary']['earned']->isZero() || ! $row['summary']['advances']->isZero() || ! $row['summary']['deductions']->isZero();
            $this->include[$id] = $owed->isPositive() || $active;
            $this->pay[$id] = $owed->toDecimal();
        }
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
