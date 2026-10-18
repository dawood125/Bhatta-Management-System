<?php

namespace App\Support\Access;

use App\Enums\Permission;
use App\Enums\Role;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the Permission and Role enums to the database. Safe to run any number of times.
 *
 * Roles are global definitions (kiln_id = null); they are *assigned* per kiln.
 */
class PermissionSynchronizer
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function sync(): void
    {
        $this->registrar->forgetCachedPermissions();
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);

        try {
            $names = array_map(fn (Permission $p) => $p->value, Permission::cases());

            foreach ($names as $name) {
                PermissionModel::findOrCreate($name, 'web');
            }

            // Permissions removed from the enum must not linger in the database.
            PermissionModel::query()->where('guard_name', 'web')->whereNotIn('name', $names)->delete();

            foreach (Role::cases() as $role) {
                /** @var RoleModel $model */
                $model = RoleModel::query()
                    ->whereNull('kiln_id')
                    ->where('name', $role->value)
                    ->where('guard_name', 'web')
                    ->first()
                    ?? RoleModel::query()->create(['name' => $role->value, 'guard_name' => 'web', 'kiln_id' => null]);

                $model->syncPermissions(array_map(fn (Permission $p) => $p->value, $role->permissions()));
            }
        } finally {
            setPermissionsTeamId($previousTeam);
            $this->registrar->forgetCachedPermissions();
        }
    }
}
