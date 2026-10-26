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
 * One debit or credit line of a journal entry. Immutable.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property Carbon $entry_date
 * @property int $ledger_account_id
 * @property int|null $party_id
 * @property int $line_no
 * @property Money $debit
 * @property Money $credit
 * @property string|null $memo
 * @property-read JournalEntry $entry
 * @property-read LedgerAccount $account
 * @property-read Party|null $party
 * @property int|null $worker_id
 * @property-read Worker|null $worker
 */
class JournalLine extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'debit' => MoneyCast::class,
            'credit' => MoneyCast::class,
            'line_no' => 'integer',
            'entry_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LedgerIntegrityException('Journal lines can never be changed.');
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Journal lines can never be deleted.');
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
