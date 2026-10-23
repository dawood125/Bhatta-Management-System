<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one sale line left with a delivery.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $sale_dispatch_id
 * @property int $sale_line_id
 * @property Quantity $quantity
 * @property-read SaleDispatch $dispatch
 * @property-read SaleLine $saleLine
 */
class SaleDispatchLine extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
        ];
    }

    /**
     * @return BelongsTo<SaleDispatch, $this>
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(SaleDispatch::class, 'sale_dispatch_id');
    }

    /**
     * @return BelongsTo<SaleLine, $this>
     */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }
}
