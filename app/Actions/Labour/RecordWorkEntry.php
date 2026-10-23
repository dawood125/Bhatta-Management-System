<?php

namespace App\Actions\Labour;

use App\Accounting\ChartOfAccounts;
use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\LabourActivity;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Models\JournalEntry;
use App\Models\LabourRate;
use App\Models\LedgerAccount;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use App\Rules\MoneyAmount;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Wages a worker earned (entry WG-…): debit the category's wages account, credit the worker.
 *
 * - Piece rate: bricks × the rate per 1,000 of that day.
 * - Daily wage: days (halves allowed) × the day rate.
 * - Contract (lump sum): the agreed amount, entered once, so it is never counted twice.
 * Salaries are posted per month from attendance (PostSalaries), not here.
 */
class RecordWorkEntry
{
    use ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ChartOfAccounts $chartOfAccounts,
        private readonly WorkerLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $input  worker_id, work_date, quantity (piece rate, daily wage), amount (contract), description
     */
    public function handle(User $actor, array $input, string $idempotencyKey): WorkEntry
    {
        $this->authorizeSettings($actor, Permission::LabourRecord);

        if (($existing = $this->findByKey($idempotencyKey)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['quantity', 'amount', 'description']);

        $data = Validator::make($input, [
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'quantity' => ['nullable'],
            'amount' => ['nullable'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'worker_id.exists' => __('This worker is archived or does not exist.'),
        ], [
            'work_date' => __('date'),
        ])->validate();

        $worker = Worker::query()->with('category')->findOrFail((int) $data['worker_id']);
        $date = Carbon::createFromFormat('Y-m-d', $data['work_date'])->startOfDay();
        $basis = $worker->category->basis;

        if ($basis === PayBasis::PerMonth) {
            throw ValidationException::withMessages(['worker_id' => __('Salaries are posted for a whole month from attendance.')]);
        }

        if ($basis === PayBasis::LumpSum) {
            Validator::make($data, ['amount' => ['required', new MoneyAmount]], [], ['amount' => __('amount')])->validate();

            return DB::transaction(fn () => $this->post($actor, $worker, $date, null, null, Money::fromRupees((string) $data['amount']), $data['description'], $idempotencyKey));
        }

        $quantity = $this->quantity($data['quantity'] ?? '', $basis);

        return DB::transaction(function () use ($actor, $worker, $date, $quantity, $data, $idempotencyKey): WorkEntry {
            $rate = $this->rateOrFail($worker, $date, 'worker_id');

            return $this->post($actor, $worker, $date, $quantity, $rate, $this->amount($rate->rate, $quantity, $worker->category->basis), $data['description'], $idempotencyKey);
        });
    }

    /**
     * Wages for the bricks of a production entry (Pathai accepted, loaded, unloaded), for a caller
     * that has checked its own permission. Must run inside the production entry's transaction.
     */
    public function forProduction(User $actor, ProductionEntry $entry, Worker $worker, Quantity $bricks): WorkEntry
    {
        $worker->loadMissing('category');
        $activity = LabourActivity::forEntryKind($entry->kind);

        if ($activity === null || $worker->category->activity !== $activity || $worker->category->basis !== PayBasis::PerThousand) {
            throw ValidationException::withMessages(['worker_id' => __(':worker is not paid for this work. Choose a worker of a matching piece-rate category.', ['worker' => $worker->displayName()])]);
        }

        $rate = $this->rateOrFail($worker, $entry->entry_date, 'worker_id');
        $amount = $this->amount($rate->rate, $bricks, PayBasis::PerThousand);

        return $this->post($actor, $worker, $entry->entry_date, $bricks, $rate, $amount, null, substr($entry->idempotency_key, 0, 58).':wages', $entry);
    }

    /**
     * Post earnings and keep their details.
     */
    public function post(User $actor, Worker $worker, CarbonInterface $date, ?Quantity $quantity, ?LabourRate $rate, Money $amount, ?string $description, string $idempotencyKey, ?ProductionEntry $production = null, ?string $salaryMonth = null): WorkEntry
    {
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['quantity' => __('These wages come to less than one paisa.')]);
        }

        $entry = $this->poster->post(JournalType::Wages, $date, [
            PendingLine::debit($this->chartOfAccounts->labourAccountFor($worker->category), $amount),
            PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE), $amount)->forWorker($worker),
        ], $idempotencyKey, $actor, $description);

        if (! $entry->wasRecentlyCreated) {
            return WorkEntry::query()->where('journal_entry_id', $entry->id)->firstOrFail();
        }

        return WorkEntry::query()->create([
            'journal_entry_id' => $entry->id,
            'worker_id' => $worker->id,
            'labour_category_id' => $worker->labour_category_id,
            'work_date' => $date->format('Y-m-d'),
            'basis' => $worker->category->basis,
            'quantity' => $quantity,
            'rate' => $rate?->rate,
            'labour_rate_id' => $rate?->id,
            'amount' => $amount,
            'description' => $description,
            'production_entry_id' => $production?->id,
            'salary_month' => $salaryMonth,
            'created_by' => $actor->id,
        ]);
    }

    private function rateOrFail(Worker $worker, CarbonInterface $date, string $field): LabourRate
    {
        $rate = $this->ledger->rateFor($worker, $date);

        if ($rate === null) {
            throw ValidationException::withMessages([$field => __('No rate is set for :category on :date. Set it in Categories and rates first.', [
                'category' => $worker->category->displayName(),
                'date' => $date->format('d-m-Y'),
            ])]);
        }

        return $rate;
    }

    private function quantity(mixed $value, PayBasis $basis): Quantity
    {
        try {
            $quantity = Quantity::fromInput((string) $value, $basis->decimals());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => $basis->decimals() === 0
                ? __('Enter a whole number.')
                : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $basis->decimals()])]);
        }

        if (! $quantity->isPositive()) {
            throw ValidationException::withMessages(['quantity' => __('The quantity must be more than zero.')]);
        }

        return $quantity;
    }

    /** Bricks are priced per 1,000; days one by one. Rounded once, half up, to the paisa. */
    private function amount(Money $rate, Quantity $quantity, PayBasis $basis): Money
    {
        return $rate->timesQuantity($quantity->toDecimal(), $basis === PayBasis::PerThousand ? 1000 : 1);
    }

    private function findByKey(string $idempotencyKey): ?WorkEntry
    {
        $entry = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->type !== JournalType::Wages) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return WorkEntry::query()->where('journal_entry_id', $entry->id)->firstOrFail();
    }
}
