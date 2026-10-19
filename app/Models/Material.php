<?php

namespace App\Models;

use App\Enums\MaterialKind;
use App\Materials\MaterialStock;
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
 * A material the kiln buys and uses: soil, Kassu, coal, Bora, Tuka, waste, ...
 * Its stock (quantity and value) always comes from its movements.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string|null $code
 * @property string $name
 * @property string|null $name_ur
 * @property int $unit_id
 * @property MaterialKind $kind
 * @property int|null $usage_account_id
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Unit $unit
 */
#[Fillable(['code', 'name', 'name_ur', 'unit_id', 'kind', 'is_active', 'sort_order'])]
class Material extends Model implements Archivable
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'kind' => MaterialKind::class,
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

    /**
     * @return HasMany<MaterialMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function usageAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'usage_account_id');
    }

    /** Decimal places allowed for this material's quantities. */
    public function decimals(): int
    {
        return $this->unit->decimals;
    }

    public function archiveBlocker(): ?string
    {
        $stock = app(MaterialStock::class)->levels([$this->id])[$this->id];

        if (! $stock['quantity']->isZero() || ! $stock['value']->isZero()) {
            return __('This material still has stock. Use it up or record a shortage first.');
        }

        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'unit_id', 'kind', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
