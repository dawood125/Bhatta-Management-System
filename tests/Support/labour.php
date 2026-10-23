<?php

use App\Actions\Labour\PostSalaries;
use App\Actions\Labour\RecordAttendance;
use App\Actions\Labour\RecordSettlement;
use App\Actions\Labour\RecordWorkEntry;
use App\Actions\Labour\RecordWorkerMoney;
use App\Actions\Labour\SaveLabourRate;
use App\Actions\Labour\SaveWorker;
use App\Models\JournalEntry;
use App\Models\LabourCategory;
use App\Models\LabourRate;
use App\Models\Settlement;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use Illuminate\Support\Str;

/** A default labour category of the current kiln by its code (pathai, kamyaar, nakaasi, barai, mistri, daily). */
function labourCategory(string $code): LabourCategory
{
    return LabourCategory::query()->where('code', $code)->sole();
}

function makeWorker(User $actor, string $name, string $categoryCode, array $extra = []): Worker
{
    return app(SaveWorker::class)->handle($actor, null, array_merge([
        'name' => $name, 'name_ur' => '', 'phone' => '', 'labour_category_id' => (string) labourCategory($categoryCode)->id, 'joined_on' => '', 'notes' => '',
    ], $extra));
}

/** A category rate (or a worker's own rate) from a date, 60 days ago by default. */
function setLabourRate(User $actor, string $categoryCode, string $rate, ?string $from = null, ?Worker $worker = null): LabourRate
{
    return app(SaveLabourRate::class)->handle($actor, [
        'labour_category_id' => (string) labourCategory($categoryCode)->id,
        'worker_id' => $worker !== null ? (string) $worker->id : '',
        'effective_from' => $from ?? now()->subDays(60)->format('Y-m-d'),
        'rate' => $rate,
    ]);
}

/** Work by hand: bricks or days for piece and daily rates, an amount for a contract. */
function recordWork(User $actor, Worker $worker, string $quantityOrAmount, ?string $date = null, ?string $key = null): WorkEntry
{
    $worker->loadMissing('category');
    $isContract = ! $worker->category->basis->hasRate();

    return app(RecordWorkEntry::class)->handle($actor, [
        'worker_id' => (string) $worker->id,
        'work_date' => $date ?? now()->format('Y-m-d'),
        'quantity' => $isContract ? '' : $quantityOrAmount,
        'amount' => $isContract ? $quantityOrAmount : '',
        'description' => '',
    ], $key ?? (string) Str::uuid());
}

function giveAdvance(User $actor, Worker $worker, TreasuryAccount $account, string $amount, ?string $date = null, string $direction = RecordWorkerMoney::GIVE): JournalEntry
{
    return app(RecordWorkerMoney::class)->advance($actor, [
        'worker_id' => (string) $worker->id,
        'account_id' => (string) $account->id,
        'entry_date' => $date ?? now()->format('Y-m-d'),
        'amount' => $amount,
        'direction' => $direction,
        'memo' => '',
    ], (string) Str::uuid());
}

function deductFrom(User $actor, Worker $worker, string $amount, string $reason = 'Broken tools', ?string $date = null): JournalEntry
{
    return app(RecordWorkerMoney::class)->deduction($actor, [
        'worker_id' => (string) $worker->id,
        'entry_date' => $date ?? now()->format('Y-m-d'),
        'amount' => $amount,
        'memo' => $reason,
    ], (string) Str::uuid());
}

/**
 * @param  array<int, string>  $paid  worker id => amount paid
 */
function settleWages(User $actor, TreasuryAccount $account, array $paid, ?string $from = null, ?string $to = null, ?string $date = null, ?string $key = null): Settlement
{
    $lines = [];
    foreach ($paid as $workerId => $amount) {
        $lines[] = ['worker_id' => (string) $workerId, 'paid' => $amount];
    }

    return app(RecordSettlement::class)->handle($actor, [
        'period_from' => $from ?? now()->subDays(6)->format('Y-m-d'),
        'period_to' => $to ?? now()->format('Y-m-d'),
        'settlement_date' => $date ?? now()->format('Y-m-d'),
        'account_id' => (string) $account->id,
        'notes' => '',
        'lines' => $lines,
    ], $key ?? (string) Str::uuid());
}

/**
 * @param  array<int, string>  $marks  worker id => present | half | absent
 */
function markAttendance(User $actor, string $date, array $marks): int
{
    return app(RecordAttendance::class)->handle($actor, ['attendance_date' => $date, 'marks' => $marks]);
}

/**
 * @return list<WorkEntry>
 */
function postSalaries(User $actor, string $month, array $workerIds = []): array
{
    return app(PostSalaries::class)->handle($actor, ['month' => $month] + ($workerIds !== [] ? ['worker_ids' => $workerIds] : []));
}

/** A worker's balance as a decimal: positive is owed to the worker, negative is an advance. */
function workerBalance(Worker $worker): string
{
    return $worker->balance()->toDecimal();
}
