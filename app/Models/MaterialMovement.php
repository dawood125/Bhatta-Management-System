<?php

namespace App\Models;

use App\Accounting\LedgerIntegrityException;
use App\Casts\MoneyCast;
use App\Casts\QuantityCast;
use App\Enums\MaterialMovementType;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One change to a material's stock: quantity and value in, or quantity and value out.
 * Never edited or deleted; a reversal is a mirror movement pointing back to this one.
 *
 * Written only by the material actions (App\Actions\Materials\*).
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $material_id
 * @property Carbon $movement_date
 * @property MaterialMovementType $type
 * @property Quantity $quantity_in
 * @property Quantity $quantity_out
 * @property Money $value_in
 * @property Money $value_out
 * @property int $journal_entry_id
 * @property int|null $material_purchase_line_id
 * @property int|null $material_usage_id
 * @property int|null $material_adjustment_id
 * @property int|null $reversal_of_id
 * @property string|null $memo
 * @property int|null $created_by
 * @property-read Material $material
 * @property-read JournalEntry $entry
 * @property-read MaterialPurchaseLine|null $purchaseLine
 * @property-read MaterialUsage|null $usage
 * @property-read MaterialAdjustment|null $adjustment
 * @property-read MaterialMovement|null $reversalOf
 * @property-read MaterialMovement|null $reversedBy
 */
class MaterialMovement extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'type' => MaterialMovementType::class,
            'quantity_in' => QuantityCast::class,
            'quantity_out' => QuantityCast::class,
            'value_in' => MoneyCast::class,
            'value_out' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LedgerIntegrityException('Stock movements cannot be changed; reverse them instead.');
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Stock movements can never be deleted; reverse them instead.');
        });
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<MaterialPurchaseLine, $this>
     */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(MaterialPurchaseLine::class, 'material_purchase_line_id');
    }

    /**
     * @return BelongsTo<MaterialUsage, $this>
     */
    public function usage(): BelongsTo
    {
        return $this->belongsTo(MaterialUsage::class, 'material_usage_id');
    }

    /**
     * @return BelongsTo<MaterialAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(MaterialAdjustment::class, 'material_adjustment_id');
    }

    /**
     * @return BelongsTo<MaterialMovement, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(MaterialMovement::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<MaterialMovement, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(MaterialMovement::class, 'reversal_of_id');
    }

    /** Net quantity: positive when stock came in. */
    public function netQuantity(): Quantity
    {
        return $this->quantity_in->subtract($this->quantity_out);
    }

    /** Net value: positive when value came in. */
    public function netValue(): Money
    {
        return $this->value_in->subtract($this->value_out);
    }
}
