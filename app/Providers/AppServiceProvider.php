<?php

namespace App\Providers;

use App\Backups\RecordBackupRun;
use App\Http\Middleware\EnsureKilnContext;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use App\Support\Mail\MailSettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

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

        // On the live server: never wipe or roll back the books, never load the demo data, and
        // build every link with https when the site runs on https.
        DB::prohibitDestructiveCommands($this->app->isProduction());
        SeedCommand::prohibit($this->app->isProduction());
        URL::forceHttps($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://'));

        // PDFs are the heaviest pages to build: at most 30 a minute per user.
        RateLimiter::for('pdf', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Livewire update requests must run with the same kiln context and language as the page.
        Livewire::addPersistentMiddleware([EnsureKilnContext::class, SetLocale::class]);

        // Email server and backup alerts set in the app (Settings → Email) replace the .env ones;
        // a queue worker reads them again for every job, so changes apply without a restart.
        $this->app->booted(fn () => app(MailSettings::class)->applyQuietly());
        Queue::before(fn () => app(MailSettings::class)->applyQuietly());

        Event::listen(BackupWasSuccessful::class, [RecordBackupRun::class, 'succeeded']);
        Event::listen(BackupHasFailed::class, [RecordBackupRun::class, 'failed']);

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
