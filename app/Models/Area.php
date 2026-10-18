<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A customer area / location (e.g. a town or route), used to group customers.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $name
 * @property string|null $name_ur
 * @property bool $is_active
 */
#[Fillable(['name', 'name_ur', 'is_active'])]
class Area extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function archiveBlocker(): ?string
    {
        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
