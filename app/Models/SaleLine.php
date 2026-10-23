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
 * One grade (or by-product) on a sale: quantity, rate per 1,000 bricks (or per unit for other
 * units), an optional discount and the amount after it.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $sale_id
 * @property int $line_no
 * @property int $product_id
 * @property Quantity $quantity
 * @property Money $rate
 * @property int $rate_per
 * @property Money $discount
 * @property Money $amount
 * @property-read Sale $sale
 * @property-read Product $product
 */
class SaleLine extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'rate' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
