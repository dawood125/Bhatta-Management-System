<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToKiln;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One salaried worker's day. It can be corrected until that month's salary is posted.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $worker_id
 * @property Carbon $attendance_date
 * @property AttendanceStatus $status
 * @property int|null $recorded_by
 * @property-read Worker $worker
 */
class Attendance extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
