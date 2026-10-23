<?php

namespace App\Models;

use App\Enums\LabourActivity;
use App\Enums\PayBasis;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A kind of labour (Pathai, Kamyaar, Nakaasi, Barai, mistri contract, ...) and how it is paid.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string|null $code
 * @property string $name
 * @property string|null $name_ur
 * @property PayBasis $basis
 * @property LabourActivity|null $activity
 * @property int|null $ledger_account_id
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['code', 'name', 'name_ur', 'basis', 'activity', 'is_active', 'sort_order'])]
class LabourCategory extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'basis' => PayBasis::class,
            'activity' => LabourActivity::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Worker, $this>
     */
    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * The category's own rates (not workers' personal rates), newest first.
     *
     * @return HasMany<LabourRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(LabourRate::class)->whereNull('worker_id')->orderByDesc('effective_from')->orderByDesc('id');
    }

    public function archiveBlocker(): ?string
    {
        if ($this->workers()->where('is_active', true)->exists()) {
            return __('Active workers belong to this category. Move or archive them first.');
        }

        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'basis', 'activity', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
