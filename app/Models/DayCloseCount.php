<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cash/bank account on a closed day: its balance before the day, money in and out, what the
 * books say at the end of the day, and what was counted (if it was).
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $day_close_id
 * @property int $treasury_account_id
 * @property Money $opening
 * @property Money $money_in
 * @property Money $money_out
 * @property Money $expected
 * @property Money|null $counted
 * @property-read TreasuryAccount $treasuryAccount
 */
class DayCloseCount extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opening' => MoneyCast::class,
            'money_in' => MoneyCast::class,
            'money_out' => MoneyCast::class,
            'expected' => MoneyCast::class,
            'counted' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<TreasuryAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    /** Counted minus the books: positive = more cash than the books say, negative = short. */
    public function difference(): ?Money
    {
        return $this->counted?->subtract($this->expected);
    }
}
