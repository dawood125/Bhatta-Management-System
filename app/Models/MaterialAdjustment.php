<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stock correction of one material: opening stock (in, with its value) or a shortage found
 * when counting (out, at average cost). Entry SA-….
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property Carbon $adjustment_date
 * @property string $kind
 * @property int $material_id
 * @property Quantity $quantity
 * @property Money $value
 * @property string|null $reason
 * @property int|null $created_by
 * @property-read JournalEntry $entry
 * @property-read Material $material
 * @property-read User|null $creator
 */
class MaterialAdjustment extends Model
{
    use BelongsToKiln, WriteOnce;

    public const OPENING = 'opening';

    public const SHORTAGE = 'shortage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'quantity' => QuantityCast::class,
            'value' => MoneyCast::class,
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
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
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
