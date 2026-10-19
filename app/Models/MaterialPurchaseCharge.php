<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PurchaseChargeKind;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra cost of a purchase (trolley, excavator, loading, ...). It is paid together with the
 * goods, owed to another party (e.g. the transporter), or paid from one of the kiln's accounts.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $material_purchase_id
 * @property int $line_no
 * @property PurchaseChargeKind $kind
 * @property string|null $description
 * @property Money $amount
 * @property string $payee
 * @property int|null $party_id
 * @property int|null $treasury_account_id
 * @property-read Party|null $party
 * @property-read TreasuryAccount|null $treasuryAccount
 */
class MaterialPurchaseCharge extends Model
{
    use BelongsToKiln, WriteOnce;

    public const WITH_GOODS = 'with_goods';

    public const TO_PARTY = 'party';

    public const FROM_ACCOUNT = 'account';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => PurchaseChargeKind::class,
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<TreasuryAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    public function label(): string
    {
        return filled($this->description) ? $this->kind->label().': '.$this->description : $this->kind->label();
    }
}
