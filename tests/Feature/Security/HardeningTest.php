<?php

use App\Enums\Role;
use App\Models\Concerns\BelongsToKiln;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('browser security headers', function () {
    it('sends them on every page, with the content policy on HTML only', function () {
        $page = $this->get('/dashboard')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeaderMissing('Strict-Transport-Security');
        expect($page->headers->get('Content-Security-Policy'))
            ->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("form-action 'self'")->toContain("object-src 'none'");

        auth()->logout();
        $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->actingAs($this->owner);
        $pdf = $this->get('/reports/profit-and-loss.pdf')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($pdf->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('limits PDFs to 30 a minute per user', function () {
        $this->get('/reports/balance-sheet.pdf')->assertOk();

        // 29 more this minute (counted directly: building 29 real PDFs would only slow the test).
        foreach (range(1, 29) as $i) {
            RateLimiter::hit(md5('pdf'.$this->owner->id), 60);
        }
        $this->get('/reports/balance-sheet.pdf')->assertStatus(429)->assertSee('Too many attempts');
        $this->get('/sales')->assertOk();
    });
});

describe('error pages', function () {
    it('explains a refused page and a missing page in English and Urdu', function () {
        $this->actingAs(makeUser($this->kiln, Role::DataEntry))->get('/accounts')->assertForbidden()
            ->assertSee('You do not have access to this page')->assertSee('آپ کو اس صفحے تک رسائی نہیں ہے');
        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertSee('صفحہ نہیں ملا');
    });

    it('shows a calm page for a server error, without any details', function () {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__test-error', fn () => throw new RuntimeException('secret detail'));

        $this->get('/__test-error')->assertStatus(500)
            ->assertSee('Something went wrong')->assertSee('کچھ غلط ہو گیا')->assertDontSee('secret detail');
    });

    it('renders every error page on its own', function (int $code, string $title) {
        expect(view("errors.{$code}", ['exception' => new HttpException($code)])->render())->toContain($title);
    })->with([
        [403, 'You do not have access to this page'],
        [404, 'Page not found'],
        [419, 'This page has expired'],
        [429, 'Too many attempts'],
        [500, 'Something went wrong'],
        [503, 'Being updated'],
    ]);
});

describe('live server guards', function () {
    afterEach(function () {
        app()['env'] = 'testing';
        (new AppServiceProvider(app()))->boot();
        URL::forceHttps(false);
    });

    it('never wipes, rolls back or seeds the database in production, and builds https links', function () {
        config(['app.url' => 'https://bhatta.example']);
        app()['env'] = 'production';
        (new AppServiceProvider(app()))->boot();

        foreach ([FreshCommand::class, RefreshCommand::class, ResetCommand::class, RollbackCommand::class, WipeCommand::class, SeedCommand::class] as $command) {
            expect((fn () => static::$prohibitedFromRunning)->bindTo(null, $command)())->toBeTrue($command);
        }
        $this->artisan('db:seed')->assertFailed();
        expect(url('/dashboard'))->toStartWith('https://');
    });

    it('allows them again outside production', function () {
        expect((fn () => static::$prohibitedFromRunning)->bindTo(null, FreshCommand::class)())->toBeFalse();
    });
});

describe('code rules', function () {
    it('checks a permission in every action, except those a user runs on their own account', function () {
        $own = ['Users/ChangeOwnPassword.php', 'Users/UpdateOwnProfile.php'];
        $missing = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Actions'))) as $file) {
            $relative = str_replace(app_path('Actions').'/', '', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_contains($relative, 'Concerns/') || in_array($relative, $own, true)) {
                continue;
            }
            if (! preg_match('/->authorize[A-Za-z]*\(|Gate::authorize|abort_unless\(.*->can\(/', (string) file_get_contents($file->getPathname()))) {
                $missing[] = $relative;
            }
        }

        expect($missing)->toBe([]);
    });

    it('scopes every table that belongs to a kiln to the current kiln', function () {
        $tables = collect(DB::select("SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'kiln_id'"))->pluck('t');
        $scoped = collect(glob(app_path('Models/*.php')))
            ->map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'))
            ->filter(fn (string $class) => in_array(BelongsToKiln::class, class_uses_recursive($class), true))
            ->map(fn (string $class) => (new $class)->getTable());

        // Framework tables, the kiln–user link, tables always read with an explicit kiln id, and
        // the test suite's own table.
        $handledElsewhere = ['activity_log', 'kiln_user', 'model_has_permissions', 'model_has_roles', 'number_sequences', 'roles', 'test_kiln_records'];

        expect($tables->diff($scoped)->diff($handledElsewhere)->values()->all())->toBe([]);
    });
});
