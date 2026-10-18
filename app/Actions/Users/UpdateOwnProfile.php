<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A user updates their own name, contact details and language. Username and role
 * can only be changed by an owner/manager.
 */
class UpdateOwnProfile
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input): void
    {
        $input['email'] = blank($input['email'] ?? null) ? null : Str::lower(trim((string) $input['email']));
        $input['phone'] = blank($input['phone'] ?? null) ? null : trim((string) $input['phone']);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'locale' => ['required', Rule::in(User::LOCALES)],
        ])->validate();

        $user->fill($data)->save();
    }
}
