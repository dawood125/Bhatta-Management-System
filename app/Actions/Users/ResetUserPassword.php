<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Lets an owner/manager set a new password for a staff member who forgot theirs.
 * The staff member is logged out of all devices.
 */
class ResetUserPassword
{
    public function __construct(
        private readonly KilnAccess $access,
        private readonly CurrentKiln $currentKiln,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, User $user, array $input): void
    {
        Gate::forUser($actor)->authorize('update', $user);

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['password' => __('Change your own password from your profile.')]);
        }

        if ($this->access->roleIn($user, $this->currentKiln->get()) === Role::Owner && ! $actor->isOwner()) {
            throw ValidationException::withMessages(['password' => __('Only an owner can create or change an owner account.')]);
        }

        $data = Validator::make($input, [
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ])->validate();

        DB::transaction(function () use ($actor, $user, $data): void {
            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            activity()->performedOn($user)->causedBy($actor)->event('password_reset')->log('password_reset');
        });
    }
}
