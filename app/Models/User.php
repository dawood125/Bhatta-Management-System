<?php

namespace App\Models;

use App\Enums\Role as RoleEnum;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * Users are shared across kilns; access to a kiln is a membership (kiln_user) plus a role
 * assigned within that kiln (spatie teams, team = kiln).
 *
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string|null $email
 * @property string|null $phone
 * @property string $locale
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
#[Fillable(['name', 'username', 'email', 'phone', 'locale', 'is_active', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    public const LOCALES = ['en', 'ur'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Kiln, $this>
     */
    public function kilns(): BelongsToMany
    {
        return $this->belongsToMany(Kiln::class)->withTimestamps();
    }

    public function belongsToKiln(Kiln|int $kiln): bool
    {
        $kilnId = $kiln instanceof Kiln ? $kiln->id : $kiln;

        return $this->kilns()->whereKey($kilnId)->exists();
    }

    /** The user's role in the current kiln (roles are kiln-scoped). */
    public function kilnRole(): ?RoleEnum
    {
        $name = $this->getRoleNames()->first();

        return is_string($name) ? RoleEnum::tryFrom($name) : null;
    }

    public function isOwner(): bool
    {
        return $this->hasRole(RoleEnum::Owner->value);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'username', 'email', 'phone', 'locale', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
