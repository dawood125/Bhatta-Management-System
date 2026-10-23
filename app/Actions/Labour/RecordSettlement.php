<?php

namespace App\Actions\Labour;

use App\Accounting\DocumentNumbers;
use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Models\LedgerAccount;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Worker;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A wage settlement (ST-…), e.g. the weekly Pathai payment or the monthly salaries.
 *
 * For each worker: brought forward + earned − advances − deductions = payable. What is paid now
 * (at most the payable) is one payment entry WP-… from the chosen account; the rest remains on
 * the worker's account for next time. Each worker's figures are kept as their payslip.
 */
class RecordSettlement
{
    use GuardsBalances, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly WorkerLedger $ledger,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $input  settlement_date, period_from, period_to, account_id, notes, lines[] = {worker_id, paid}
     */
    public function handle(User $actor, array $input, string $idempotencyKey): Settlement
    {
        $this->authorizeSettings($actor, Permission::LabourPay);

        if (($existing = $this->existing($idempotencyKey)) !== null) {
            return $existing;
        }

        $lines = is_array($input['lines'] ?? null) ? array_values(array_map(fn ($l) => is_array($l) ? $this->clean($l, []) : $l, $input['lines'])) : null;
        $input = $this->clean(array_diff_key($input, ['lines' => true]), ['notes']) + ['lines' => $lines];
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:today'],
            'settlement_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_to', 'before_or_equal:today'],
            'account_id' => ['required', 'integer', Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['required', 'array'],
            'lines.*.worker_id' => ['required', 'integer', 'distinct', Rule::exists('workers', 'id')->where('kiln_id', $kilnId)],
            'lines.*.paid' => ['required', new MoneyAmount(allowZero: true)],
        ], [
            'settlement_date.after_or_equal' => __('Pay on or after the last day of the period.'),
            'lines.required' => __('Choose at least one worker.'),
            'lines.min' => __('Choose at least one worker.'),
        ], [
            'period_from' => __('from date'),
            'period_to' => __('to date'),
            'settlement_date' => __('payment date'),
            'account_id' => __('account'),
            'lines.*.paid' => __('amount paid'),
        ])->validate();

        $from = Carbon::createFromFormat('Y-m-d', $data['period_from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $data['period_to'])->startOfDay();
        $date = Carbon::createFromFormat('Y-m-d', $data['settlement_date'])->startOfDay();
        $rows = array_values($data['lines']);

        try {
            return DB::transaction(function () use ($actor, $data, $from, $to, $date, $rows, $idempotencyKey): Settlement {
                $account = $this->lockTreasuryAccounts([(int) $data['account_id']])->sole();
                if ($date->lt($account->opening_date)) {
                    throw ValidationException::withMessages(['settlement_date' => __('The date is before the opening date of :account (:date).', [
                        'account' => $account->displayName(),
                        'date' => $account->opening_date->format('d-m-Y'),
                    ])]);
                }

                $workerIds = array_map(fn ($row) => (int) $row['worker_id'], $rows);
                $workers = Worker::query()->whereKey($workerIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $summary = $this->ledger->periodSummary($workerIds, $from, $to);
                // Anything recorded after the period but before paying also counts in what is owed.
                $owedNow = $this->ledger->balances($workerIds, $date);

                $lines = [];
                $total = Money::zero();
                foreach ($rows as $index => $row) {
                    $workerId = (int) $row['worker_id'];
                    $paid = Money::fromRupees((string) $row['paid']);
                    $limit = Money::fromPaisa(max(0, $owedNow[$workerId]->paisa));

                    if ($paid->greaterThan($limit)) {
                        throw ValidationException::withMessages(["lines.{$index}.paid" => __('More than :worker is owed (:amount). Give an advance instead.', [
                            'worker' => $workers[$workerId]->displayName(),
                            'amount' => $limit->formatWithCurrency(),
                        ])]);
                    }

                    $total = $total->add($paid);
                    $lines[] = ['worker' => $workers[$workerId], 'summary' => $summary[$workerId], 'paid' => $paid];
                }

                $entry = null;
                if ($total->isPositive()) {
                    $pending = [PendingLine::credit($account->ledgerAccount, $total)];
                    $wages = LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE);
                    foreach ($lines as $line) {
                        if ($line['paid']->isPositive()) {
                            $pending[] = PendingLine::debit($wages, $line['paid'])->forWorker($line['worker']);
                        }
                    }

                    $entry = $this->poster->post(JournalType::WagePayment, $date, $pending, $idempotencyKey, $actor, $data['notes']);
                    $this->assertNotNegative($account, 'account_id');
                }

                $settlement = Settlement::query()->create([
                    'journal_entry_id' => $entry?->id,
                    'number' => $this->numbers->next('ST', $date),
                    'settlement_date' => $date->format('Y-m-d'),
                    'period_from' => $from->format('Y-m-d'),
                    'period_to' => $to->format('Y-m-d'),
                    'treasury_account_id' => $account->id,
                    'total_paid' => $total,
                    'notes' => $data['notes'],
                    'idempotency_key' => trim($idempotencyKey),
                    'created_by' => $actor->id,
                ]);

                foreach ($lines as $line) {
                    $settlement->lines()->create([
                        'worker_id' => $line['worker']->id,
                        'brought_forward' => $line['summary']['brought_forward'],
                        'earned' => $line['summary']['earned'],
                        'advances' => $line['summary']['advances'],
                        'deductions' => $line['summary']['deductions'],
                        'payable' => $line['summary']['payable'],
                        'paid' => $line['paid'],
                        'remaining' => $line['summary']['payable']->subtract($line['paid']),
                    ]);
                }

                return $settlement;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced each other: return the settlement that won.
            if (($existing = $this->existing($idempotencyKey)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    private function existing(string $idempotencyKey): ?Settlement
    {
        return Settlement::query()->where('idempotency_key', trim($idempotencyKey))->first();
    }
}
