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

/**
 * One material on a purchase: quantity × rate, plus its share of the extra charges.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $material_purchase_id
 * @property int $line_no
 * @property int $material_id
 * @property Quantity $quantity
 * @property Money $rate
 * @property Money $amount
 * @property Money $charges
 * @property Money $cost
 * @property-read MaterialPurchase $purchase
 * @property-read Material $material
 */
class MaterialPurchaseLine extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'rate' => MoneyCast::class,
            'amount' => MoneyCast::class,
            'charges' => MoneyCast::class,
            'cost' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<MaterialPurchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(MaterialPurchase::class, 'material_purchase_id');
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
