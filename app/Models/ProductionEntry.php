<?php

namespace App\Models;

use App\Accounting\LedgerIntegrityException;
use App\Casts\QuantityCast;
use App\Enums\ProductionEntryKind;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One production entry (Pathai, loading, firing, Nakaasi or stock adjustment) and its brick
 * movements. Never edited or deleted: the only change allowed is marking it reversed, together
 * with the mirror movements that cancel it.
 *
 * Written only by the production actions (App\Actions\Production\*).
 *
 * @property int $id
 * @property int $kiln_id
 * @property ProductionEntryKind $kind
 * @property string $number
 * @property Carbon $entry_date
 * @property string|null $team
 * @property int|null $worker_id
 * @property Quantity|null $made
 * @property Quantity|null $rejected
 * @property Quantity $quantity
 * @property string|null $notes
 * @property int|null $material_usage_id
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property-read Collection<int, BrickMovement> $movements
 * @property-read MaterialUsage|null $materialUsage
 * @property-read Worker|null $worker
 * @property-read WorkEntry|null $workEntry
 * @property-read User|null $creator
 * @property-read User|null $reverser
 */
class ProductionEntry extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => ProductionEntryKind::class,
            'entry_date' => 'date',
            'made' => QuantityCast::class,
            'rejected' => QuantityCast::class,
            'quantity' => QuantityCast::class,
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProductionEntry $entry): void {
            $allowed = ['reversed_at', 'reversed_by', 'reversal_reason', 'updated_at'];

            if (array_diff(array_keys($entry->getDirty()), $allowed) !== [] || $entry->getOriginal('reversed_at') !== null) {
                throw new LedgerIntegrityException('Production entries cannot be changed; reverse them instead.');
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Production entries can never be deleted; reverse them instead.');
        });
    }

    /**
     * The entry's own movements (not their reversals).
     *
     * @return HasMany<BrickMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(BrickMovement::class)->whereNull('reversal_of_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<MaterialUsage, $this>
     */
    public function materialUsage(): BelongsTo
    {
        return $this->belongsTo(MaterialUsage::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * The wages the named worker earned with this entry.
     *
     * @return HasOne<WorkEntry, $this>
     */
    public function workEntry(): HasOne
    {
        return $this->hasOne(WorkEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Pathai: bricks accepted (made minus rejected). */
    public function accepted(): Quantity
    {
        return ($this->made ?? Quantity::zero())->subtract($this->rejected ?? Quantity::zero());
    }
}
