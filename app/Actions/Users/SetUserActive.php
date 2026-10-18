<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Activates or deactivates a staff account. A deactivated user is logged out everywhere
 * immediately and cannot log in again.
 */
class SetUserActive
{
    public function __construct(
        private readonly KilnAccess $access,
        private readonly CurrentKiln $currentKiln,
    ) {}

    public function handle(User $actor, User $user, bool $active): void
    {
        Gate::forUser($actor)->authorize('update', $user);

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => __('You cannot deactivate your own account.')]);
        }

        $kiln = $this->currentKiln->get();
        $role = $this->access->roleIn($user, $kiln);

        if ($role === Role::Owner && ! $actor->isOwner()) {
            throw ValidationException::withMessages(['user' => __('Only an owner can create or change an owner account.')]);
        }

        if (! $active && $role === Role::Owner && $user->is_active && $this->access->activeOwnerCount($kiln) <= 1) {
            throw ValidationException::withMessages(['user' => __('The kiln must keep at least one active owner.')]);
        }

        if ($user->is_active === $active) {
            return;
        }

        DB::transaction(function () use ($user, $active): void {
            $user->is_active = $active;

            if (! $active) {
                $user->setRememberToken(Str::random(60));
            }

            $user->save();

            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });
    }
}
