<?php

namespace App\Models;

use App\Models\Concerns\BelongsToKiln;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A trolley the kiln owns, with the driver who runs it.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $number
 * @property int $driver_id
 * @property bool $is_active
 * @property-read Party $driver
 */
#[Fillable(['number', 'driver_id', 'is_active'])]
class Trolley extends Model
{
    use BelongsToKiln, LogsActivity;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'driver_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'driver_id', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
