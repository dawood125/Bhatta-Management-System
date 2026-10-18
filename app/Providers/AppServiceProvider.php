<?php

namespace App\Providers;

use App\Http\Middleware\EnsureKilnContext;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentKiln::class);
    }

    public function boot(): void
    {
        // Catch lazy loading, silently discarded attributes and missing attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Livewire update requests must run with the same kiln context and language as the page.
        Livewire::addPersistentMiddleware([EnsureKilnContext::class, SetLocale::class]);

        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $user = $event->user;
            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            $kilnId = $user->kilns()->where('kilns.is_active', true)->orderBy('kilns.id')->value('kilns.id');

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('logged_in')
                ->withProperties(['ip' => request()->ip()])
                ->tap(fn ($activity) => $activity->kiln_id = $kilnId)
                ->log('logged_in');
        });

        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(base_path('tests/database/migrations'));
        }
    }
}
