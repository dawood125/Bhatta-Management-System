<?php

namespace App\Models;

use App\Labour\WorkerLedger;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A worker, family or team leader paid by the kiln. Its balance always comes from the journal:
 * positive means the kiln owes the worker, negative means the worker owes the kiln (an advance).
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $name
 * @property string|null $name_ur
 * @property string|null $phone
 * @property int $labour_category_id
 * @property Carbon|null $joined_on
 * @property string|null $notes
 * @property bool $is_active
 * @property-read LabourCategory $category
 */
#[Fillable(['name', 'name_ur', 'phone', 'labour_category_id', 'joined_on', 'notes', 'is_active'])]
class Worker extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LabourCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LabourCategory::class, 'labour_category_id');
    }

    /** Positive: the kiln owes the worker. Negative: the worker owes the kiln. */
    public function balance(?Carbon $asOf = null): Money
    {
        return app(WorkerLedger::class)->balances([$this->id], $asOf)[$this->id];
    }

    public function archiveBlocker(): ?string
    {
        if (! $this->balance()->isZero()) {
            return __('Only a worker with a zero balance can be archived. Settle the account first.');
        }

        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'phone', 'labour_category_id', 'joined_on', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
