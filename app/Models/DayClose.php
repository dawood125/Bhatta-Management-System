<?php

namespace App\Models;

use App\Accounting\LedgerIntegrityException;
use App\Models\Concerns\BelongsToKiln;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A closed day: the cash was counted against the books, and nothing new can be dated on or
 * before this day (except by the owner) until it is reopened. Never deleted; reopening only
 * records who reopened it, when and why.
 *
 * @property int $id
 * @property int $kiln_id
 * @property Carbon $close_date
 * @property string|null $notes
 * @property int|null $closed_by
 * @property Carbon|null $reopened_at
 * @property int|null $reopened_by
 * @property string|null $reopen_reason
 * @property Carbon $created_at
 * @property-read Collection<int, DayCloseCount> $counts
 * @property-read User|null $closer
 * @property-read User|null $reopener
 */
class DayClose extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'close_date' => 'date',
            'reopened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DayClose $close): void {
            $allowed = ['reopened_at', 'reopened_by', 'reopen_reason', 'updated_at'];

            if (array_diff(array_keys($close->getDirty()), $allowed) !== [] || $close->getOriginal('reopened_at') !== null) {
                throw new LedgerIntegrityException('A closed day cannot be changed; reopen it instead.');
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Closed days are never deleted; reopen them instead.');
        });
    }

    /**
     * @return HasMany<DayCloseCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(DayCloseCount::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isReopened(): bool
    {
        return $this->reopened_at !== null;
    }
}
