<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a staff account in the current kiln, including its role.
 */
class SaveUser
{
    public function __construct(
        private readonly KilnAccess $access,
        private readonly CurrentKiln $currentKiln,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'locale' => ['required', Rule::in(User::LOCALES)],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => $user === null ? ['required', 'string', Password::default(), 'confirmed'] : ['prohibited'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?User $user, array $input): User
    {
        Gate::forUser($actor)->authorize($user ? 'update' : 'create', $user ?? User::class);

        $input['username'] = Str::lower(trim((string) ($input['username'] ?? '')));
        $input['email'] = blank($input['email'] ?? null) ? null : Str::lower(trim((string) $input['email']));
        $input['phone'] = blank($input['phone'] ?? null) ? null : trim((string) $input['phone']);
        if ($user !== null && blank($input['password'] ?? null)) {
            unset($input['password'], $input['password_confirmation']);
        }

        $data = Validator::make($input, self::rules($user))->validate();

        $kiln = $this->currentKiln->get();
        $newRole = Role::from($data['role']);
        $oldRole = $user ? $this->access->roleIn($user, $kiln) : null;

        if (($newRole === Role::Owner || $oldRole === Role::Owner) && ! $actor->isOwner()) {
            throw ValidationException::withMessages(['role' => __('Only an owner can create or change an owner account.')]);
        }

        if ($user !== null && $user->is($actor) && $newRole !== $oldRole) {
            throw ValidationException::withMessages(['role' => __('You cannot change your own role.')]);
        }

        if ($user !== null && $oldRole === Role::Owner && $newRole !== Role::Owner
            && $user->is_active && $this->access->activeOwnerCount($kiln) <= 1) {
            throw ValidationException::withMessages(['role' => __('The kiln must keep at least one active owner.')]);
        }

        return DB::transaction(function () use ($actor, $user, $data, $kiln, $newRole, $oldRole): User {
            $attributes = Arr::only($data, ['name', 'username', 'email', 'phone', 'locale']);

            if ($user !== null) {
                $user->fill($attributes)->save();
            } else {
                $user = User::query()->create($attributes + ['password' => $data['password']]);
            }

            $this->access->grant($user, $kiln, $newRole);

            if ($oldRole !== $newRole) {
                activity()
                    ->performedOn($user)
                    ->causedBy($actor)
                    ->event('role_changed')
                    ->withProperties([
                        'old' => ['role' => $oldRole?->value],
                        'attributes' => ['role' => $newRole->value],
                    ])
                    ->log('role_changed');
            }

            return $user;
        });
    }
}
