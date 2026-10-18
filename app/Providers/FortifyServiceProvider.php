<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));

        // Log in with username or email. Inactive accounts and accounts without kiln access are refused.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $login = Str::lower(trim((string) $request->input(Fortify::username())));
            $password = (string) $request->input('password');

            $user = User::query()
                ->where('username', $login)
                ->orWhere('email', $login)
                ->first();

            if ($user === null) {
                // Same work as a real check, so response time does not reveal which usernames exist.
                Hash::check($password, '$2y$12$'.str_repeat('a', 53));

                return null;
            }

            if (! Hash::check($password, $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('This account has been deactivated. Please contact the kiln owner.'),
                ]);
            }

            if (! $user->kilns()->where('kilns.is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('This account has no access to any kiln.'),
                ]);
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
