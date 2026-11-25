<?php

namespace App\Actions\Labour;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Worker;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit a worker (a person, a family or a team leader paid by the kiln).
 *
 * The opening balance is what stood between the kiln and the worker when the kiln started using
 * the system: wages already due (credit "Wages payable" for the worker) or an advance already
 * taken (debit it), against "Opening balances". Changing it later reverses the old opening entry
 * on its own date and posts a new one, exactly like parties and cash/bank accounts.
 */
class SaveWorker
{
    use ValidatesSettings;

    /** The kiln owes the worker: wages already due. */
    public const KILN_OWES = 'kiln_owes';

    /** The worker owes the kiln: an advance already taken. */
    public const WORKER_OWES = 'worker_owes';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  name, name_ur, phone, labour_category_id, joined_on, notes,
     *                                       opening_amount, opening_direction, opening_date (all three optional)
     */
    public function handle(User $actor, ?Worker $worker, array $input, ?string $idempotencyKey = null): Worker
    {
        $this->authorizeSettings($actor, Permission::LabourWorkers);
        $idempotencyKey ??= (string) Str::uuid();

        // The same "Add worker" form submitted twice: return the worker it already created.
        if ($worker === null && ($existing = Worker::query()->where('creation_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['name_ur', 'phone', 'joined_on', 'notes', 'opening_amount', 'opening_date']);
        $input['opening_amount'] ??= '0';
        $input['opening_date'] ??= null;
        foreach (['name_ur', 'phone', 'joined_on', 'notes'] as $optional) {
            $input[$optional] ??= null;
        }
        $input['opening_direction'] = ($input['opening_direction'] ?? '') !== '' ? $input['opening_direction'] : self::KILN_OWES;

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'name_ur' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'labour_category_id' => ['required', 'integer', Rule::exists('labour_categories', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)],
            'joined_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            'opening_amount' => ['required', new MoneyAmount(allowZero: true)],
            'opening_direction' => ['required', Rule::in([self::KILN_OWES, self::WORKER_OWES])],
            'opening_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'labour_category_id.exists' => __('Choose an active labour category.'),
        ], [
            'labour_category_id' => __('category'),
            'joined_on' => __('joining date'),
            'opening_amount' => __('opening balance'),
            'opening_date' => __('as of date'),
        ])->validate();

        $amount = Money::fromRupees($data['opening_amount']);
        $opening = $data['opening_direction'] === self::WORKER_OWES ? $amount->negate() : $amount;

        if (! $opening->isZero() && $data['opening_date'] === null) {
            throw ValidationException::withMessages(['opening_date' => __('Enter the date of the opening balance.')]);
        }
        $openingDate = $data['opening_date'] !== null ? Carbon::createFromFormat('Y-m-d', $data['opening_date'])->startOfDay() : null;

        try {
            return $this->save($actor, $worker, $data, $opening, $openingDate, $idempotencyKey);
        } catch (UniqueConstraintViolationException $e) {
            // Two identical "Add worker" requests raced each other: return the one that won.
            if ($worker === null && ($existing = Worker::query()->where('creation_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(User $actor, ?Worker $worker, array $data, Money $opening, ?Carbon $openingDate, string $idempotencyKey): Worker
    {
        return DB::transaction(function () use ($actor, $worker, $data, $opening, $openingDate, $idempotencyKey): Worker {
            $attributes = [
                'name' => $data['name'],
                'name_ur' => $data['name_ur'],
                'phone' => $data['phone'],
                'labour_category_id' => (int) $data['labour_category_id'],
                'joined_on' => $data['joined_on'],
                'notes' => $data['notes'],
            ];

            if ($worker === null) {
                $worker = Worker::query()->create($attributes + [
                    'opening_balance' => $opening,
                    'opening_date' => $opening->isZero() ? null : $openingDate?->format('Y-m-d'),
                    'is_active' => true,
                    'creation_key' => $idempotencyKey,
                ]);

                if (! $opening->isZero() && $openingDate !== null) {
                    $worker->opening_entry_id = $this->postOpening($actor, $worker, $opening, $openingDate, $idempotencyKey.':open')->id;
                    $worker->save();
                }

                return $worker;
            }

            /** @var Worker $worker */
            $worker = Worker::query()->whereKey($worker->id)->lockForUpdate()->firstOrFail();
            $worker->fill($attributes)->save();

            $newDate = $opening->isZero() ? null : $openingDate?->format('Y-m-d');
            $openingChanged = ! $worker->opening_balance->equals($opening)
                || $worker->opening_date?->format('Y-m-d') !== $newDate;

            if ($openingChanged) {
                if ($openingDate !== null && ! $opening->isZero()) {
                    $this->assertNoEntriesBefore($worker, $openingDate);
                }

                if ($worker->opening_entry_id !== null) {
                    $old = JournalEntry::query()->findOrFail($worker->opening_entry_id);
                    // Dated like the original so the two cancel out at the same point in history.
                    $this->poster->reverse($old, $old->entry_date, 'Opening balance changed', $idempotencyKey.':rev', $actor);
                }

                $worker->opening_balance = $opening;
                $worker->opening_date = $opening->isZero() ? null : $openingDate;
                $worker->opening_entry_id = $opening->isZero() || $openingDate === null
                    ? null
                    : $this->postOpening($actor, $worker, $opening, $openingDate, $idempotencyKey.':open')->id;
                $worker->save();
            }

            return $worker;
        });
    }

    private function postOpening(User $actor, Worker $worker, Money $opening, Carbon $date, string $key): JournalEntry
    {
        $equity = LedgerAccount::bySystemKey(LedgerAccount::OPENING_BALANCE_EQUITY);
        $wages = LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE);
        $amount = $opening->abs();

        $lines = $opening->isPositive()
            // The kiln owes the worker: credit the worker.
            ? [PendingLine::debit($equity, $amount), PendingLine::credit($wages, $amount)->forWorker($worker)]
            // The worker owes the kiln (advance): debit the worker.
            : [PendingLine::debit($wages, $amount)->forWorker($worker), PendingLine::credit($equity, $amount)];

        return $this->poster->post(JournalType::OpeningBalance, $date, $lines, $key, $actor);
    }

    /** Transactions dated before the opening date would make old statements wrong. */
    private function assertNoEntriesBefore(Worker $worker, Carbon $openingDate): void
    {
        $earlier = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.worker_id', $worker->id)
            ->whereNotIn('journal_entries.type', [JournalType::OpeningBalance->value, JournalType::Reversal->value])
            ->where('journal_entries.entry_date', '<', $openingDate->format('Y-m-d'))
            ->exists();

        if ($earlier) {
            throw ValidationException::withMessages([
                'opening_date' => __('This worker has transactions before this date. Choose an earlier opening date.'),
            ]);
        }
    }
}
