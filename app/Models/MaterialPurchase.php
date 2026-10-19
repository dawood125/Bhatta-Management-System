<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A purchase of materials, with any extra charges (trolley, excavator, ...) added to their cost.
 * The money side is its journal entry (PU-…); the stock side is one movement per line.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property Carbon $purchase_date
 * @property int|null $supplier_id
 * @property string $payment
 * @property int|null $treasury_account_id
 * @property string|null $reference
 * @property string|null $notes
 * @property Money $goods_total
 * @property Money $charges_total
 * @property Money $total
 * @property int|null $created_by
 * @property-read JournalEntry $entry
 * @property-read Party|null $supplier
 * @property-read TreasuryAccount|null $treasuryAccount
 * @property-read Collection<int, MaterialPurchaseLine> $lines
 * @property-read Collection<int, MaterialPurchaseCharge> $charges
 * @property-read User|null $creator
 */
class MaterialPurchase extends Model
{
    use BelongsToKiln, WriteOnce;

    public const ON_CREDIT = 'credit';

    public const PAID_NOW = 'paid';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'goods_total' => MoneyCast::class,
            'charges_total' => MoneyCast::class,
            'total' => MoneyCast::class,
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
     * @return BelongsTo<Party, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<TreasuryAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    /**
     * @return HasMany<MaterialPurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MaterialPurchaseLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<MaterialPurchaseCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(MaterialPurchaseCharge::class)->orderBy('line_no');
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
