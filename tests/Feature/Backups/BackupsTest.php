<?php

use App\Backups\BackupCatalog;
use App\Backups\BackupDrill;
use App\Backups\BackupRestorer;
use App\Backups\BackupRunner;
use App\Enums\Role;
use App\Livewire\Backups\BackupIndex;
use App\Models\Activity;
use App\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    Storage::fake('backups');
    $this->dumpName = 'db-dumps/mysql-'.DB::connection()->getDatabaseName().'.sql';
});

function backupZip(): ZipArchive
{
    $newest = app(BackupCatalog::class)->newest();
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path($newest['path']));

    return $zip;
}

describe('access', function () {
    it('is for the owner only', function () {
        $file = fakeBackup(now()->format('Y-m-d-H-i-s'));
        $this->get('/backups')->assertOk()->assertSee('Back up now');
        $this->get('/dashboard')->assertSee(route('backups.index'), false);

        foreach ([Role::Manager, Role::Munshi, Role::DataEntry, Role::Viewer] as $role) {
            $user = makeUser($this->kiln, $role);
            $this->actingAs($user)->get('/backups')->assertForbidden();
            $this->actingAs($user)->get(route('backups.download', ['disk' => 'backups', 'file' => $file]))->assertForbidden();
            $this->actingAs($user)->get('/dashboard')->assertDontSee(route('backups.index'), false);
        }
    });
});

describe('making backups', function () {
    it('backs up now: the database and the files in one zip, with who made it in the history', function () {
        Livewire::test(BackupIndex::class)->call('backUpNow')->assertDispatched('toast', type: 'success', message: 'Backup made.');

        $run = BackupRun::query()->sole();
        expect($run->kind)->toBe(BackupRun::MANUAL)->and($run->status)->toBe(BackupRun::SUCCEEDED)
            ->and($run->user_id)->toBe($this->owner->id)->and($run->size_bytes)->toBeGreaterThan(1000)
            ->and(backupZip()->getFromName($this->dumpName))->toContain('CREATE TABLE `journal_lines`')
            ->and(Activity::query()->where('event', 'backup_made')->exists())->toBeTrue();

        $this->get('/backups')->assertSee('Backups are up to date.')->assertSee('Backup by '.$this->owner->name);
    });

    it('records a failed backup and says why', function () {
        config(['database.connections.mysql.dump.dump_binary_path' => '/no/such/folder']);

        Livewire::test(BackupIndex::class)->call('backUpNow')->assertDispatched('toast', type: 'error');

        $run = BackupRun::query()->sole();
        expect($run->status)->toBe(BackupRun::FAILED)->and($run->message)->not->toBeEmpty();
        $this->get('/backups')->assertSee('Failed')->assertSee('There are no backups yet.');
    });

    it('locks the backup with the backup password when one is set', function () {
        config(['backup.backup.password' => 'a long test password']);
        app(BackupRunner::class)->run($this->owner);

        $zip = backupZip();
        expect($zip->statName($this->dumpName)['encryption_method'])->toBe(ZipArchive::EM_AES_256)
            ->and($zip->getFromName($this->dumpName))->toBeFalse();
        $zip->setPassword('a long test password');
        expect($zip->getFromName($this->dumpName))->toContain('CREATE TABLE');

        $this->get('/backups')->assertSee('Yes. Keep the backup password safe');
    });
});

describe('the list and downloads', function () {
    it('lists backups newest first and warns when the newest is more than a day old', function () {
        $this->get('/backups')->assertSee('There are no backups yet.');

        fakeBackup(now()->subDays(3)->format('Y-m-d-H-i-s'));
        fakeBackup(now()->subDays(2)->format('Y-m-d-H-i-s'));
        $this->get('/backups')->assertSee('No backup in the last day.')
            ->assertSeeInOrder([now()->subDays(2)->format('d-m-Y'), now()->subDays(3)->format('d-m-Y')]);

        fakeBackup(now()->subHour()->format('Y-m-d-H-i-s'));
        $this->get('/backups')->assertSee('Backups are up to date.');
    });

    it('downloads a listed backup, and nothing else', function () {
        $file = fakeBackup(now()->format('Y-m-d-H-i-s'));
        Storage::disk('backups')->put('elsewhere/secret.zip', 'not a backup');

        $this->get(route('backups.download', ['disk' => 'backups', 'file' => $file]))->assertOk()->assertDownload('bhatta-manager-'.$file);
        expect(Activity::query()->where('event', 'backup_downloaded')->exists())->toBeTrue();

        $this->get('/backups/backups/2020-01-01-00-00-00.zip')->assertNotFound();
        $this->get('/backups/backups/secret.zip')->assertNotFound();
        $this->get('/backups/public/'.$file)->assertNotFound();
        $this->get('/backups/backups/..%2F..%2F.env')->assertNotFound();
    });
});

describe('restoring', function () {
    it('never overwrites the live database by accident', function () {
        $live = DB::connection()->getDatabaseName();
        $restorer = app(BackupRestorer::class);

        expect(fn () => $restorer->restore(['disk' => 'backups', 'path' => 'x.zip', 'file' => 'x.zip'], $live))->toThrow(RuntimeException::class, 'Refusing to overwrite the live database.')
            ->and(fn () => $restorer->emptyDatabase($live))->toThrow(RuntimeException::class, 'Refusing to overwrite the live database.');
    });

    it('refuses a backup with unsafe paths, or opened with the wrong password', function () {
        $make = function (string $name, array $entries, ?string $password = null): array {
            $path = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            foreach ($entries as $entry => $content) {
                $zip->addFromString($entry, $content);
                if ($password) {
                    $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
                }
            }
            $zip->close();
            Storage::disk('backups')->put("bhatta-manager/{$name}", (string) file_get_contents($path));
            unlink($path);

            return ['disk' => 'backups', 'path' => "bhatta-manager/{$name}", 'file' => $name];
        };

        $restorer = app(BackupRestorer::class);
        $unsafe = $make('unsafe.zip', ['db-dumps/mysql-x.sql' => 'SELECT 1;', 'public/../../evil.php' => '<?php']);
        expect(fn () => $restorer->restore($unsafe, 'gbricks_restore'))->toThrow(RuntimeException::class, 'unsafe path');

        config(['backup.backup.password' => 'the wrong one']);
        $locked = $make('locked.zip', ['db-dumps/mysql-x.sql' => 'SELECT 1;'], 'the right one');
        expect(fn () => $restorer->restore($locked, 'gbricks_restore'))->toThrow(RuntimeException::class, 'cannot be unpacked');
    });

    it('skips the restore drill when no drill database is set', function () {
        config(['backup.drill_database' => null]);
        $this->artisan('kiln:backup-drill')->assertSuccessful()->expectsOutputToContain('No drill database is set');
        $this->get('/backups')->assertDontSee('Test a restore')->assertSee('Not set up.');
    });

    it('restores a fresh backup into the drill database and finds it the same as the live one', function () {
        config(['backup.drill_database' => 'gbricks_restore']);

        Livewire::test(BackupIndex::class)->call('testRestore')->assertDispatched('toast', type: 'success');

        $run = BackupRun::query()->where('kind', BackupRun::DRILL)->sole();
        expect($run->succeeded())->toBeTrue((string) $run->message)
            ->and($run->details['tables'])->toBeGreaterThan(40)
            ->and(app(BackupRestorer::class)->tables('gbricks_restore'))->toBe([]);
        $this->get('/backups')->assertSee('came back the same')->assertSee('Last: '.now()->format('d-m-Y').', passed.');
    })->skip(fn () => ! drillDatabaseAvailable(), 'needs the gbricks_restore database');

    it('notices a restored copy that differs, and restores into another database from the command line', function () {
        $restorer = app(BackupRestorer::class);
        app(BackupRunner::class)->run($this->owner);

        $this->artisan('kiln:restore-backup', ['--into' => 'gbricks_restore', '--force' => true])->assertSuccessful()
            ->expectsOutputToContain('The live database was not touched.');

        $restorer->connection('gbricks_restore')->table('migrations')->where('id', 1)->delete();
        $differences = app(BackupDrill::class)->compare(DB::connection()->getDatabaseName(), 'gbricks_restore')['differences'];
        $restorer->emptyDatabase('gbricks_restore');

        expect($differences)->toContain('Table migrations: '.DB::table('migrations')->count().' rows live, '.(DB::table('migrations')->count() - 1).' in the copy.');
    })->skip(fn () => ! drillDatabaseAvailable(), 'needs the gbricks_restore database');
});
