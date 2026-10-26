<?php

namespace App\Models;

use App\Accounting\LedgerIntegrityException;
use App\Casts\MoneyCast;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sale (invoice INV-…): what the customer ordered and owes for. Its journal entry makes the
 * customer owe the total; its bricks stay reserved until they are delivered (SaleDispatch).
 *
 * Never edited or deleted. The only changes are its status and, once, either the cancellation
 * of the whole sale (nothing delivered yet) or the closing of what was not delivered.
 *
 * Written only by the sales actions (App\Actions\Sales\*).
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $number
 * @property Carbon $sale_date
 * @property int $customer_id
 * @property int|null $agent_id
 * @property int $journal_entry_id
 * @property Money $total
 * @property SaleStatus $status
 * @property string|null $notes
 * @property int|null $receipt_entry_id
 * @property int|null $credit_entry_id
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property string|null $close_reason
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property-read Party $customer
 * @property-read Party|null $agent
 * @property-read JournalEntry $entry
 * @property-read JournalEntry|null $receiptEntry
 * @property-read JournalEntry|null $creditEntry
 * @property-read Collection<int, SaleLine> $lines
 * @property-read Collection<int, SaleDispatch> $dispatches
 * @property-read Collection<int, SaleReceipt> $receipts
 * @property-read User|null $creator
 * @property-read User|null $closer
 * @property-read User|null $canceller
 */
class Sale extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'total' => MoneyCast::class,
            'status' => SaleStatus::class,
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Sale $sale): void {
            $allowed = ['status', 'credit_entry_id', 'closed_at', 'closed_by', 'close_reason', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'updated_at'];

            if (array_diff(array_keys($sale->getDirty()), $allowed) !== [] || $sale->getOriginal('status') === SaleStatus::Cancelled) {
                throw new LedgerIntegrityException('Sales cannot be changed; cancel them or close what was not delivered instead.');
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Sales can never be deleted; cancel them instead.');
        });
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'agent_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function receiptEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'receipt_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function creditEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'credit_entry_id');
    }

    /**
     * @return HasMany<SaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('line_no');
    }

    /**
     * Deliveries, newest first (reversed ones included).
     *
     * @return HasMany<SaleDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(SaleDispatch::class)->orderByDesc('dispatch_date')->orderByDesc('id');
    }

    /**
     * Receipts taken from this sale's page (money received with the sale is receiptEntry).
     *
     * @return HasMany<SaleReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(SaleReceipt::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === SaleStatus::Cancelled;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
