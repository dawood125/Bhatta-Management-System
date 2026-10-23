<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\StageKind;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bricks moving from one stage to the next (or into / out of the stages). Product applies to
 * the saleable side only: raw, in-firing and awaiting-Nakaasi bricks are not graded yet.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $production_entry_id
 * @property Carbon $movement_date
 * @property StageKind|null $stage_from
 * @property StageKind|null $stage_to
 * @property int|null $product_id
 * @property Quantity $quantity
 * @property int|null $reversal_of_id
 * @property int|null $created_by
 * @property-read ProductionEntry $entry
 * @property-read Product|null $product
 */
class BrickMovement extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'stage_from' => StageKind::class,
            'stage_to' => StageKind::class,
            'quantity' => QuantityCast::class,
        ];
    }

    /**
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class, 'production_entry_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
