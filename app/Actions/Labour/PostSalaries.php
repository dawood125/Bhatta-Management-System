<?php

namespace App\Actions\Labour;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\AttendanceStatus;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A month's salaries (entries WG-…): the monthly rate, less 1/30 of it for each absent day and
 * 1/60 for each half day (days before joining count as absent). Each worker's salary is posted
 * once per month; reversing it allows posting again.
 */
class PostSalaries
{
    use ValidatesSettings;

    public function __construct(
        private readonly RecordWorkEntry $recordWorkEntry,
        private readonly WorkerLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $input  month (YYYY-MM), worker_ids (optional: all salaried workers)
     * @return list<WorkEntry> the salaries posted now
     */
    public function handle(User $actor, array $input): array
    {
        $this->authorizeSettings($actor, Permission::LabourRecord);

        $data = Validator::make($input, [
            'month' => ['required', 'date_format:Y-m'],
            'worker_ids' => ['nullable', 'array'],
            'worker_ids.*' => ['integer'],
        ], [], ['month' => __('month')])->validate();

        $start = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        if ($start->isAfter(now())) {
            throw ValidationException::withMessages(['month' => __('This month has not started yet.')]);
        }
        $end = $start->copy()->endOfMonth()->startOfDay();
        $postDate = $end->isAfter(now()) ? now()->startOfDay() : $end;

        $workers = Worker::query()
            ->with('category')
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('basis', PayBasis::PerMonth->value))
            ->when($data['worker_ids'] ?? null, fn ($q, array $ids) => $q->whereKey($ids))
            ->orderBy('name')
            ->get();

        return DB::transaction(function () use ($actor, $data, $start, $end, $postDate, $workers): array {
            Worker::query()->whereKey($workers->pluck('id'))->orderBy('id')->lockForUpdate()->get();

            $posted = [];
            foreach ($workers as $worker) {
                $already = WorkEntry::query()
                    ->where('worker_id', $worker->id)
                    ->where('salary_month', $data['month'])
                    ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
                    ->exists();
                if ($already) {
                    continue;
                }

                $rate = $this->ledger->rateFor($worker, $postDate);
                if ($rate === null) {
                    throw ValidationException::withMessages(['month' => __('No salary is set for :worker. Set it in Categories and rates first.', ['worker' => $worker->displayName()])]);
                }

                $unpaidHalves = $this->unpaidHalves($worker, $start, $end);
                $paidHalves = max(0, 60 - $unpaidHalves);
                $amount = $rate->rate->subtract($rate->rate->timesQuantity($unpaidHalves, 60));
                if (! $amount->isPositive()) {
                    continue; // absent the whole month: nothing earned
                }

                $posted[] = $this->recordWorkEntry->post(
                    $actor,
                    $worker,
                    $postDate,
                    Quantity::fromMilli($paidHalves * 500), // paid days, on a 30-day month
                    $rate,
                    $amount,
                    null,
                    'salary:'.$worker->id.':'.$data['month'].':'.WorkEntry::query()->where('worker_id', $worker->id)->where('salary_month', $data['month'])->count(),
                    salaryMonth: $data['month'],
                );
            }

            return $posted;
        });
    }

    /** Unpaid half days in the month: marked absences and half days, and days before joining. */
    public function unpaidHalves(Worker $worker, Carbon $start, Carbon $end): int
    {
        $halves = 0;

        $marks = Attendance::query()
            ->where('worker_id', $worker->id)
            ->whereBetween('attendance_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get()
            ->keyBy(fn (Attendance $a) => $a->attendance_date->format('Y-m-d'));

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($worker->joined_on !== null && $day->lt($worker->joined_on)) {
                $halves += 2;

                continue;
            }

            $halves += $marks->get($day->format('Y-m-d'))?->status->unpaidHalves() ?? AttendanceStatus::Present->unpaidHalves();
        }

        return $halves;
    }

    /** What a month's salary would be, for the screen before posting. */
    public function preview(Worker $worker, string $month): ?Money
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $rate = $this->ledger->rateFor($worker, $end->isAfter(now()) ? now() : $end);

        if ($rate === null) {
            return null;
        }

        $amount = $rate->rate->subtract($rate->rate->timesQuantity($this->unpaidHalves($worker, $start, $end), 60));

        return $amount->isPositive() ? $amount : Money::zero();
    }
}
