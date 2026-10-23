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
 * A wage settlement (number ST-…): for each worker, what they earned in the period, what was
 * advanced and deducted, what was owed, what was paid now and what remains. The payments are
 * one journal entry (WP-…); the lines are kept as they were for the payslips.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int|null $journal_entry_id
 * @property string $number
 * @property Carbon $settlement_date
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property int $treasury_account_id
 * @property Money $total_paid
 * @property string|null $notes
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property-read JournalEntry|null $entry
 * @property-read TreasuryAccount $treasuryAccount
 * @property-read Collection<int, SettlementLine> $lines
 * @property-read User|null $creator
 */
class Settlement extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'total_paid' => MoneyCast::class,
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
     * @return BelongsTo<TreasuryAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    /**
     * @return HasMany<SettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class)->orderBy('id');
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
        return $this->entry?->isReversed() ?? false;
    }
}
