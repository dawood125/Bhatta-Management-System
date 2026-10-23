<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Casts\QuantityCast;
use App\Enums\PayBasis;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Wages a worker earned (entry WG-…): bricks at a piece rate, days at a daily wage, a month's
 * salary, or a contract amount. The rate is copied from the rate card of that day.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property int $worker_id
 * @property int $labour_category_id
 * @property Carbon $work_date
 * @property PayBasis $basis
 * @property Quantity|null $quantity
 * @property Money|null $rate
 * @property int|null $labour_rate_id
 * @property Money $amount
 * @property string|null $description
 * @property int|null $production_entry_id
 * @property string|null $salary_month
 * @property int|null $created_by
 * @property-read JournalEntry $entry
 * @property-read Worker $worker
 * @property-read LabourCategory $category
 * @property-read ProductionEntry|null $productionEntry
 * @property-read User|null $creator
 */
class WorkEntry extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'basis' => PayBasis::class,
            'quantity' => QuantityCast::class,
            'rate' => MoneyCast::class,
            'amount' => MoneyCast::class,
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
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<LabourCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LabourCategory::class, 'labour_category_id');
    }

    /**
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function productionEntry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class);
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
