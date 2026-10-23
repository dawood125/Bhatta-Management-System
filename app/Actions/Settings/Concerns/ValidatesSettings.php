<?php

namespace App\Actions\Settings\Concerns;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared checks for the kiln-settings actions.
 */
trait ValidatesSettings
{
    protected function authorizeSettings(User $actor, Permission $permission = Permission::SettingsManage): void
    {
        Gate::forUser($actor)->authorize($permission->value);
    }

    protected function kilnId(): int
    {
        return app(CurrentKiln::class)->id();
    }

    /** "Unique within this kiln" (the database also enforces this with a unique index). */
    protected function uniqueInKiln(string $table, string $column, ?int $ignoreId = null): Unique
    {
        $kilnId = $this->kilnId();

        return (new Unique($table, $column))
            ->where(fn ($query) => $query->where('kiln_id', $kilnId))
            ->ignore($ignoreId);
    }

    /**
     * Trim text fields and turn empty optional fields into null.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $optional
     * @return array<string, mixed>
     */
    protected function clean(array $input, array $optional = ['name_ur']): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            }
        }

        foreach ($optional as $key) {
            if (array_key_exists($key, $input) && blank($input[$key])) {
                $input[$key] = null;
            }
        }

        return $input;
    }
}
