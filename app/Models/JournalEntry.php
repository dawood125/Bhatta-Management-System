<?php

namespace App\Models;

use App\Accounting\LedgerIntegrityException;
use App\Casts\MoneyCast;
use App\Enums\JournalType;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One balanced double-entry transaction. Never edited or deleted once posted: the only change
 * allowed is marking it "reversed" when a reversal entry is posted against it.
 *
 * Created only through App\Accounting\JournalPoster.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $number
 * @property Carbon $entry_date
 * @property JournalType $type
 * @property string $status
 * @property string|null $memo
 * @property Money $total
 * @property int|null $reversal_of_id
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property-read Collection<int, JournalLine> $lines
 * @property-read JournalEntry|null $reversalOf
 * @property-read JournalEntry|null $reversedBy
 * @property-read User|null $creator
 */
class JournalEntry extends Model
{
    use BelongsToKiln;

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'type' => JournalType::class,
            'total' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry): void {
            $changed = array_keys($entry->getDirty());
            $allowed = ['status', 'updated_at'];

            if (array_diff($changed, $allowed) !== []
                || ($entry->isDirty('status') && ! ($entry->getOriginal('status') === self::STATUS_POSTED && $entry->status === self::STATUS_REVERSED))) {
                throw new LedgerIntegrityException('Posted journal entries cannot be changed; post a reversal instead.');
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Journal entries can never be deleted; post a reversal instead.');
        });
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<JournalEntry, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reversal_of_id');
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
        return $this->status === self::STATUS_REVERSED;
    }

    public function canBeReversed(): bool
    {
        return ! $this->isReversed() && $this->type->isUserReversible();
    }
}
