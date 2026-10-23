<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One worker on a settlement, as it was settled (the payslip):
 * brought forward + earned − advances − deductions = payable; payable − paid = remaining.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $settlement_id
 * @property int $worker_id
 * @property Money $brought_forward
 * @property Money $earned
 * @property Money $advances
 * @property Money $deductions
 * @property Money $payable
 * @property Money $paid
 * @property Money $remaining
 * @property-read Settlement $settlement
 * @property-read Worker $worker
 */
class SettlementLine extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'brought_forward' => MoneyCast::class,
            'earned' => MoneyCast::class,
            'advances' => MoneyCast::class,
            'deductions' => MoneyCast::class,
            'payable' => MoneyCast::class,
            'paid' => MoneyCast::class,
            'remaining' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Settlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
