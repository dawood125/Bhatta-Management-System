<?php

namespace App\Models;

use App\Support\Kiln\CurrentKiln;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit log entry. Every entry records the kiln it happened in, so each kiln
 * only ever sees its own history.
 *
 * @property int|null $kiln_id
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            $activity->kiln_id ??= app(CurrentKiln::class)->idOrNull();

            // A kiln's own history (e.g. its creation) belongs to that kiln.
            if ($activity->kiln_id === null && $activity->subject_type === (new Kiln)->getMorphClass()) {
                $activity->kiln_id = (int) $activity->subject_id;
            }
        });
    }

    /**
     * @param  Builder<Activity>  $query
     */
    public function scopeForCurrentKiln(Builder $query): void
    {
        $query->where('kiln_id', app(CurrentKiln::class)->id());
    }

    /**
     * @return BelongsTo<Kiln, $this>
     */
    public function kiln(): BelongsTo
    {
        return $this->belongsTo(Kiln::class);
    }
}
