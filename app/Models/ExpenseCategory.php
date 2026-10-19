<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $kiln_id
 * @property int|null $ledger_account_id
 * @property string $name
 * @property string|null $name_ur
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['name', 'name_ur', 'is_active', 'sort_order'])]
class ExpenseCategory extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ExpenseSubCategory, $this>
     */
    public function subCategories(): HasMany
    {
        return $this->hasMany(ExpenseSubCategory::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function archiveBlocker(): ?string
    {
        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
