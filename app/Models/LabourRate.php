<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rate from a date on: the category's rate, or one worker's own rate (e.g. a salary). The rate
 * of a day is the latest one that started on or before it; the worker's own rate wins.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $labour_category_id
 * @property int|null $worker_id
 * @property Carbon $effective_from
 * @property Money $rate
 * @property int|null $created_by
 * @property-read LabourCategory $category
 * @property-read Worker|null $worker
 * @property-read User|null $creator
 */
class LabourRate extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rate' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<LabourCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LabourCategory::class, 'labour_category_id');
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
