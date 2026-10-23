<?php

namespace App\Actions\Labour;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Worker;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Money between the kiln and a worker outside a settlement:
 * - Advance (peshgi), entry AD-…: cash given to the worker, or paid back by the worker.
 * - Deduction, entry DD-…: taken off the worker's wages with a reason (fine, damage, food ...).
 * Both are recovered at the next settlement, because they lower the worker's balance.
 */
class RecordWorkerMoney
{
    use GuardsBalances, ValidatesSettings;

    public const GIVE = 'give';

    public const RECEIVE = 'receive';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  worker_id, account_id, entry_date, amount, direction (give|receive), memo
     */
    public function advance(User $actor, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::LabourPay);
        $input = $this->clean($input, ['memo']);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'account_id' => ['required', 'integer', Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'direction' => ['required', Rule::in([self::GIVE, self::RECEIVE])],
            'memo' => ['nullable', 'string', 'max:255'],
        ], [
            'worker_id.exists' => __('This worker is archived or does not exist.'),
            'account_id.exists' => __('This account is archived or does not exist.'),
        ], ['entry_date' => __('date'), 'account_id' => __('account')])->validate();

        $worker = Worker::query()->findOrFail((int) $data['worker_id']);
        $amount = Money::fromRupees((string) $data['amount']);
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();
        $giving = $data['direction'] === self::GIVE;

        return DB::transaction(function () use ($actor, $data, $worker, $amount, $date, $giving, $idempotencyKey): JournalEntry {
            $account = $this->lockTreasuryAccounts([(int) $data['account_id']])->sole();
            if ($date->lt($account->opening_date)) {
                throw ValidationException::withMessages(['entry_date' => __('The date is before the opening date of :account (:date).', [
                    'account' => $account->displayName(),
                    'date' => $account->opening_date->format('d-m-Y'),
                ])]);
            }

            $wages = LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE);
            $lines = $giving
                ? [PendingLine::debit($wages, $amount)->forWorker($worker), PendingLine::credit($account->ledgerAccount, $amount)]
                : [PendingLine::debit($account->ledgerAccount, $amount), PendingLine::credit($wages, $amount)->forWorker($worker)];

            $entry = $this->poster->post(JournalType::WorkerAdvance, $date, $lines, $idempotencyKey, $actor, $data['memo']);
            $this->assertNotNegative($account, 'amount');

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $input  worker_id, entry_date, amount, memo (the reason)
     */
    public function deduction(User $actor, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::LabourRecord);
        $input = $this->clean($input, []);

        $data = Validator::make($input, [
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'memo' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'worker_id.exists' => __('This worker is archived or does not exist.'),
            'memo.required' => __('Say what the deduction is for, e.g. broken tools, food.'),
        ], ['entry_date' => __('date')])->validate();

        $worker = Worker::query()->findOrFail((int) $data['worker_id']);
        $amount = Money::fromRupees((string) $data['amount']);

        return DB::transaction(fn () => $this->poster->post(
            JournalType::WorkerDeduction,
            Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay(),
            [
                PendingLine::debit(LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE), $amount)->forWorker($worker),
                PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::WORKER_DEDUCTIONS), $amount),
            ],
            $idempotencyKey,
            $actor,
            $data['memo'],
        ));
    }
}
