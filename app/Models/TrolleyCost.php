<?php

namespace App\Models;

use App\Accounting\LedgerIntegrityException;
use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Diesel, maintenance or another running cost of a trolley, taken from its driver's carriage.
 * The money side is its journal entry; a mistake is corrected by reversing that entry.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property Carbon $cost_date
 * @property int $trolley_id
 * @property int $driver_id
 * @property string $kind
 * @property int|null $treasury_account_id
 * @property int|null $supplier_id
 * @property Money $amount
 * @property string|null $description
 * @property int|null $created_by
 * @property-read JournalEntry $entry
 * @property-read Trolley $trolley
 * @property-read Party $driver
 */
class TrolleyCost extends Model
{
    use BelongsToKiln;

    public const DIESEL = 'diesel';

    public const MAINTENANCE = 'maintenance';

    public const OTHER = 'other';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost_date' => 'date', 'amount' => MoneyCast::class];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LedgerIntegrityException('Trolley costs cannot be changed; reverse the entry instead.');
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Trolley costs can never be deleted; reverse the entry instead.');
        });
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [self::DIESEL => __('Diesel'), self::MAINTENANCE => __('Maintenance'), self::OTHER => __('Other')];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<Trolley, $this>
     */
    public function trolley(): BelongsTo
    {
        return $this->belongsTo(Trolley::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'driver_id');
    }
}
