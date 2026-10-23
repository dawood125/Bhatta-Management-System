<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Unit of measure, e.g. brick, ton, cubic foot, trolley.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string|null $code
 * @property string $name
 * @property string|null $name_ur
 * @property string|null $symbol
 * @property int $decimals
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['code', 'name', 'name_ur', 'symbol', 'decimals', 'is_system', 'is_active', 'sort_order'])]
class Unit extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    public const BRICK = 'brick';

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function archiveBlocker(): ?string
    {
        if ($this->is_system) {
            return __('This unit is used by the system and cannot be archived.');
        }

        if ($this->products()->where('is_active', true)->exists()) {
            return __('Active products use this unit. Change or archive those products first.');
        }

        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'symbol', 'decimals', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
