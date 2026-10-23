<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Database\Factories\KilnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_ur
 * @property string|null $address
 * @property string|null $address_ur
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $logo_path
 * @property bool $is_active
 */
#[Fillable(['name', 'name_ur', 'address', 'address_ur', 'phone', 'email', 'logo_path', 'is_active'])]
class Kiln extends Model
{
    /** @use HasFactory<KilnFactory> */
    use HasFactory, HasLocalizedName, LogsActivity;

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

    /** Address in the current UI language (falls back to the English address). */
    public function displayAddress(): ?string
    {
        return app()->getLocale() === 'ur' && filled($this->address_ur) ? $this->address_ur : $this->address;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'address', 'address_ur', 'phone', 'email', 'logo_path', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
