<?php

namespace App\Models;

use Database\Factories\KilnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_ur
 * @property string|null $address
 * @property string|null $phone
 * @property bool $is_active
 */
#[Fillable(['name', 'name_ur', 'address', 'phone', 'is_active'])]
class Kiln extends Model
{
    /** @use HasFactory<KilnFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** Name in the current UI language (Urdu name when set and Urdu is active). */
    public function displayName(): string
    {
        return app()->getLocale() === 'ur' && filled($this->name_ur) ? $this->name_ur : $this->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'address', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
