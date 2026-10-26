<?php

namespace App\Support;

use App\Accounting\LedgerVerifier;
use App\Backups\BackupCatalog;
use App\Backups\BackupDrill;
use App\Models\AppSetting;
use App\Models\BackupRun;
use App\Models\Kiln;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use App\Support\Mail\MailSettings;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Is this installation set up right? Each check gives ok, warn (works, but should be fixed) or
 * fail (something will not work). Run with `php artisan kiln:check` after every deployment.
 */
class SetupCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const EXTENSIONS = ['ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'json', 'libxml', 'mbstring', 'openssl', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip', 'zlib'];

    /** @var list<array{group: string, check: string, status: string, detail: string}> */
    private array $results = [];

    public function __construct(
        private readonly BackupCatalog $backups,
        private readonly BackupDrill $drill,
        private readonly LedgerVerifier $ledger,
    ) {}

    /** @return list<array{group: string, check: string, status: string, detail: string}> */
    public function run(): array
    {
        $this->results = [];
        $production = app()->isProduction();

        // Application
        $this->add('App', 'Environment', $production ? self::OK : self::WARN, (string) app()->environment().($production ? '' : ' (not production)'));
        $this->add('App', 'Debug mode', config('app.debug') ? ($production ? self::FAIL : self::WARN) : self::OK, config('app.debug') ? 'on: errors show code details to users' : 'off');
        $this->add('App', 'Application key', filled(config('app.key')) ? self::OK : self::FAIL, filled(config('app.key')) ? 'set' : 'missing: run php artisan key:generate');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->add('App', 'Address (APP_URL)', $https ? self::OK : ($production ? self::FAIL : self::WARN), (string) config('app.url'));
        $this->add('App', 'Secure session cookie', ! $https || config('session.secure') ? self::OK : self::WARN, config('session.secure') ? 'on' : 'off: set SESSION_SECURE_COOKIE=true on https');
        $this->add('App', 'Time zone', config('app.timezone') === 'Asia/Karachi' ? self::OK : self::WARN, (string) config('app.timezone'));
        $this->add('App', 'Cached configuration', app()->configurationIsCached() ? self::OK : ($production ? self::WARN : self::OK), app()->configurationIsCached() ? 'cached' : 'not cached (php artisan optimize)');

        // PHP
        $this->add('PHP', 'Version', version_compare(PHP_VERSION, '8.3.0', '>=') ? self::OK : self::FAIL, PHP_VERSION);
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));
        $this->add('PHP', 'Extensions', $missing === [] ? self::OK : self::FAIL, $missing === [] ? 'all present' : 'missing: '.implode(', ', $missing));

        // Database
        try {
            $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
            $this->add('Database', 'Connection', self::OK, DB::connection()->getDatabaseName().' on '.$version);

            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $ran);
            $this->add('Database', 'Migrations', $pending === [] ? self::OK : self::FAIL, $pending === [] ? count($ran).' run, none pending' : count($pending).' pending: php artisan migrate --force');

            $kilns = Kiln::query()->where('is_active', true)->get();
            $owners = $kilns->sum(fn (Kiln $kiln) => app(CurrentKiln::class)->run($kiln, fn () => app(KilnAccess::class)->activeOwnerCount($kiln)));
            $this->add('Database', 'Kiln and owner', $kilns->isNotEmpty() && $owners > 0 ? self::OK : self::FAIL, $kilns->isEmpty() ? 'no kiln yet: php artisan kiln:install' : $kilns->pluck('name')->implode(', '));

            $problems = $this->ledger->verify();
            $this->add('Database', 'Ledger', $problems === [] ? self::OK : self::FAIL, $problems === [] ? 'consistent' : count($problems).' problem(s): php artisan ledger:verify');
        } catch (Throwable $e) {
            $this->add('Database', 'Connection', self::FAIL, $e->getMessage());
        }

        // Files
        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache'), 'PDF work folder' => storage_path('framework/mpdf')] as $label => $path) {
            $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
            $this->add('Files', "{$label} writable", $writable ? self::OK : self::FAIL, $path);
        }
        $this->add('Files', 'Public storage link', is_link(public_path('storage')) || is_dir(public_path('storage')) ? self::OK : self::FAIL, is_link(public_path('storage')) ? 'linked' : 'missing: php artisan storage:link (kiln logo)');
        $this->add('Files', 'Built assets', is_file(public_path('build/manifest.json')) ? self::OK : self::FAIL, is_file(public_path('build/manifest.json')) ? 'present' : 'missing: npm ci && npm run build');

        // Background work
        $this->add('Background', 'Queue', config('queue.default') === 'sync' ? ($production ? self::FAIL : self::WARN) : self::OK, (string) config('queue.default'));
        $this->heartbeat('Queue worker', Health::QUEUE, 'invoice emails wait until it runs (supervisor)');
        $this->heartbeat('Scheduler', Health::SCHEDULER, 'no nightly backup without it (cron)');
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $this->add('Background', 'Failed jobs (24 h)', $failed === 0 ? self::OK : self::WARN, (string) $failed);
        $mailer = (string) config('mail.default');
        $notSending = in_array($mailer, ['log', 'array'], true);
        $this->add('Background', 'Email', $notSending ? ($production ? self::WARN : self::OK) : self::OK,
            $notSending ? "{$mailer}: emails are not sent (set them up in Settings > Email)" : ($mailer === MailSettings::MAILER ? 'Settings > Email: '.config('mail.mailers.'.MailSettings::MAILER.'.host') : $mailer));

        // Backups
        foreach (['Database dump tool' => $this->dumpBinary(), 'Database restore tool' => (string) config('backup.restore_client', 'mysql')] as $label => $binary) {
            $version = $this->version($binary);
            $this->add('Backups', $label, $version !== null ? self::OK : self::FAIL, $version ?? "{$binary} not found");
        }
        $newest = $this->backups->newest();
        $this->add('Backups', 'Newest backup', $this->backups->isHealthy() ? self::OK : ($production ? self::FAIL : self::WARN), $newest ? $newest['date']->format('d-m-Y H:i').' ('.$newest['date']->diffForHumans().')' : 'none yet');
        $settings = AppSetting::current();
        $copy = BackupRun::query()->where('kind', BackupRun::COPY)->latest('id')->first();
        if ($settings->hasGoogleDrive()) {
            $recent = $copy !== null && $copy->succeeded() && $copy->created_at->greaterThan(now()->subHours(BackupCatalog::HEALTHY_HOURS));
            $this->add('Backups', 'Off-server copy', $recent ? self::OK : self::WARN, 'Google Drive ('.($settings->google_drive_email ?? '?').'): '
                .($copy === null ? 'no copy yet' : 'last copy '.$copy->created_at->format('d-m-Y H:i').' '.$copy->status));
        } else {
            $this->add('Backups', 'Off-server copy', count($this->backups->disks()) > 1 ? self::OK : self::WARN, implode(', ', $this->backups->disks()).(count($this->backups->disks()) > 1 ? '' : ': only on this server (connect Google Drive on the Backups page)'));
        }
        $this->add('Backups', 'Backup password', $this->backups->isEncrypted() ? self::OK : self::WARN, $this->backups->isEncrypted() ? 'set' : 'not set (BACKUP_ARCHIVE_PASSWORD)');
        $this->add('Backups', 'Failure emails', $this->backups->alertEmails() !== [] ? self::OK : self::WARN, $this->backups->alertEmails() !== [] ? implode(', ', $this->backups->alertEmails()) : 'not set (Settings > Email)');
        $drill = BackupRun::query()->where('kind', BackupRun::DRILL)->latest('id')->first();
        $this->add('Backups', 'Restore drill', ! $this->drill->isConfigured() ? self::WARN : ($drill === null || $drill->succeeded() ? self::OK : self::FAIL),
            ! $this->drill->isConfigured() ? 'not set up (BACKUP_DRILL_DATABASE)' : ($drill === null ? 'set up, not run yet: php artisan kiln:backup-drill' : 'last '.$drill->created_at->format('d-m-Y').': '.$drill->status));

        return $this->results;
    }

    public function hasFailures(): bool
    {
        return collect($this->results)->contains('status', self::FAIL);
    }

    private function heartbeat(string $label, string $what, string $consequence): void
    {
        $last = Health::lastBeat($what);
        $fresh = $last !== null && $last->greaterThan(now()->subMinutes(15));
        $this->add('Background', $label, $fresh ? self::OK : (app()->isProduction() ? self::FAIL : self::WARN),
            $last === null ? "never seen: {$consequence}" : 'last seen '.$last->diffForHumans().($fresh ? '' : ": {$consequence}"));
    }

    private function dumpBinary(): string
    {
        $path = (string) config('database.connections.'.config('database.default').'.dump.dump_binary_path', '');

        return ($path !== '' ? rtrim($path, '/').'/' : '').'mysqldump';
    }

    private function version(string $binary): ?string
    {
        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function add(string $group, string $check, string $status, string $detail): void
    {
        $this->results[] = compact('group', 'check', 'status', 'detail');
    }
}
