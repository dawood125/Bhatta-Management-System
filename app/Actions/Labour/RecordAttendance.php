<?php

namespace App\Actions\Labour;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\AttendanceStatus;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A day's attendance of salaried workers: present, half day or absent. It can be corrected until
 * that month's salary has been posted for the worker.
 */
class RecordAttendance
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  attendance_date, marks = [worker_id => status]
     */
    public function handle(User $actor, array $input): int
    {
        $this->authorizeSettings($actor, Permission::LabourRecord);

        $data = Validator::make($input, [
            'attendance_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*' => ['required', Rule::enum(AttendanceStatus::class)],
        ], [], ['attendance_date' => __('date')])->validate();

        $date = Carbon::createFromFormat('Y-m-d', $data['attendance_date'])->startOfDay();
        $workerIds = array_map('intval', array_keys($data['marks']));
        $workers = Worker::query()->with('category')->whereKey($workerIds)->get()->keyBy('id');

        foreach ($workerIds as $id) {
            $worker = $workers->get($id);
            if ($worker === null || $worker->category->basis !== PayBasis::PerMonth) {
                throw ValidationException::withMessages(['marks' => __('Attendance is kept for salaried workers only.')]);
            }
        }

        $posted = WorkEntry::query()
            ->whereIn('worker_id', $workerIds)
            ->where('salary_month', $date->format('Y-m'))
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->with('worker')
            ->first();
        if ($posted !== null) {
            throw ValidationException::withMessages(['marks' => __('The salary of :worker for this month is already posted. Reverse it to change attendance.', ['worker' => $posted->worker->displayName()])]);
        }

        return DB::transaction(function () use ($actor, $data, $date): int {
            foreach ($data['marks'] as $workerId => $status) {
                Attendance::query()->updateOrCreate(
                    ['worker_id' => (int) $workerId, 'attendance_date' => $date->format('Y-m-d')],
                    ['status' => AttendanceStatus::from($status), 'recorded_by' => $actor->id],
                );
            }

            return count($data['marks']);
        });
    }
}
