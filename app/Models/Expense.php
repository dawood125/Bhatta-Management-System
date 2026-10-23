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
 * A kiln expense paid from a cash/bank account. The money side is its journal entry; this row
 * keeps the category, sub-category and details for reports. Like the entry, it is never edited
 * or deleted: a mistake is corrected by reversing the entry.
 *
 * Created only through App\Actions\Expenses\RecordExpense.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $journal_entry_id
 * @property Carbon $expense_date
 * @property int $expense_category_id
 * @property int|null $expense_sub_category_id
 * @property int $treasury_account_id
 * @property Money $amount
 * @property string|null $description
 * @property int|null $created_by
 * @property-read JournalEntry $entry
 * @property-read ExpenseCategory $category
 * @property-read ExpenseSubCategory|null $subCategory
 * @property-read TreasuryAccount $treasuryAccount
 * @property-read User|null $creator
 */
class Expense extends Model
{
    use BelongsToKiln;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LedgerIntegrityException('Expenses cannot be changed; reverse the entry instead.');
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Expenses can never be deleted; reverse the entry instead.');
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
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<ExpenseSubCategory, $this>
     */
    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubCategory::class, 'expense_sub_category_id');
    }

    /**
     * @return BelongsTo<TreasuryAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
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
