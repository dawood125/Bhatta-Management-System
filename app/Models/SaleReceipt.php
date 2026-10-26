<?php

namespace App\Models;

use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\WriteOnce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt taken for one sale (from the sale's page): it pays that sale first.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $sale_id
 * @property int $journal_entry_id
 * @property int|null $created_by
 * @property-read Sale $sale
 * @property-read JournalEntry $entry
 */
class SaleReceipt extends Model
{
    use BelongsToKiln, WriteOnce;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
