<?php

namespace App\Models;

use App\Enums\ProductKind;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A brick grade (Awal, Doom, ...), raw brick (Kachi) or by-product (Rora, ...).
 *
 * @property int $id
 * @property int $kiln_id
 * @property string|null $code
 * @property string $name
 * @property string|null $name_ur
 * @property ProductKind $kind
 * @property int $unit_id
 * @property bool $is_saleable
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Unit $unit
 */
#[Fillable(['code', 'name', 'name_ur', 'kind', 'unit_id', 'is_saleable', 'is_active', 'sort_order'])]
class Product extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'is_saleable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function archiveBlocker(): ?string
    {
        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'kind', 'unit_id', 'is_saleable', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
