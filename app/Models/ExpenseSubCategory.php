<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $kiln_id
 * @property int $expense_category_id
 * @property string $name
 * @property string|null $name_ur
 * @property bool $is_active
 * @property-read ExpenseCategory $category
 */
#[Fillable(['expense_category_id', 'name', 'name_ur', 'is_active'])]
class ExpenseSubCategory extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function archiveBlocker(): ?string
    {
        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['expense_category_id', 'name', 'name_ur', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
