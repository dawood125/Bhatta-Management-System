<?php

namespace App\Support\Access;

use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;

/**
 * Grants a user access to a kiln with exactly one role in that kiln.
 */
class KilnAccess
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    public function grant(User $user, Kiln $kiln, Role $role): void
    {
        $user->kilns()->syncWithoutDetaching([$kiln->id]);

        $this->currentKiln->run($kiln, function () use ($user, $role): void {
            $user->unsetRelation('roles');
            $user->syncRoles([$role->value]);
            $user->unsetRelation('roles');
        });
    }

    public function roleIn(User $user, Kiln $kiln): ?Role
    {
        return $this->currentKiln->run($kiln, function () use ($user): ?Role {
            $user->unsetRelation('roles');
            $role = $user->kilnRole();
            $user->unsetRelation('roles');

            return $role;
        });
    }

    /** Number of active users holding the Owner role in the kiln. */
    public function activeOwnerCount(Kiln $kiln): int
    {
        return $kiln->users()
            ->where('users.is_active', true)
            ->whereHas('roles', fn ($q) => $q
                ->where('name', Role::Owner->value)
                ->where(config('permission.table_names.model_has_roles').'.kiln_id', $kiln->id))
            ->count();
    }
}
