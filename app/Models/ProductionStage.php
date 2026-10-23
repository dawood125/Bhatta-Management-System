<?php

namespace App\Models;

use App\Enums\StageKind;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A kiln's name for one of the fixed production stages. Only the names can change.
 *
 * @property int $id
 * @property int $kiln_id
 * @property StageKind $kind
 * @property string $name
 * @property string|null $name_ur
 * @property int $sort_order
 */
#[Fillable(['kind', 'name', 'name_ur', 'sort_order'])]
class ProductionStage extends Model
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'kind' => StageKind::class,
            'sort_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
