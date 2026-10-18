<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * A user changes their own password. All their other sessions (other devices) are logged out.
 */
class ChangeOwnPassword
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?string $currentSessionId): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default(), 'confirmed', 'different:current_password'],
        ])->validate();

        DB::transaction(function () use ($user, $data, $currentSessionId): void {
            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->when($currentSessionId, fn ($q) => $q->where('id', '!=', $currentSessionId))
                ->delete();

            activity()->performedOn($user)->causedBy($user)->event('password_changed')->log('password_changed');
        });
    }
}
