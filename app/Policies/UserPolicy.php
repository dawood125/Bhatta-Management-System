<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(Permission::UsersManage->value)
            && $target->belongsToKiln(app(CurrentKiln::class)->id());
    }
}
