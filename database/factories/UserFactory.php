<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Access\KilnAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => Str::lower(Str::random(3)).fake()->unique()->numberBetween(1000, 999999),
            'email' => null,
            'phone' => null,
            'locale' => 'en',
            'is_active' => true,
            'last_login_at' => null,
            'email_verified_at' => null,
            'password' => static::$password ??= Hash::make('password1'),
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Give the user access to a kiln with a role (after creating). */
    public function inKiln(Kiln $kiln, Role $role = Role::Viewer): static
    {
        return $this->afterCreating(function (User $user) use ($kiln, $role): void {
            app(KilnAccess::class)->grant($user, $kiln, $role);
        });
    }
}
