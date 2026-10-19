<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Materials taken out of stock and used (e.g. the day's coal and Bora). Each material is one
 * movement, valued at its average cost; the entry (MU-…) moves that cost into expenses.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property Carbon $usage_date
 * @property string|null $notes
 * @property Money $total_cost
 * @property int|null $created_by
 * @property-read JournalEntry $entry
 * @property-read Collection<int, MaterialMovement> $movements
 * @property-read User|null $creator
 */
class MaterialUsage extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'total_cost' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * The usage's own movements (not their reversals).
     *
     * @return HasMany<MaterialMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class)->whereNull('reversal_of_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversed(): bool
    {
        return $this->entry->isReversed();
    }
}
