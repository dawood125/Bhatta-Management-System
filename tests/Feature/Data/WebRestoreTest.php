<?php

use App\Backups\BackupRestorer;
use App\Backups\BackupRunner;
use App\Backups\WebRestore;
use App\Enums\Role;
use App\Livewire\Backups\BackupIndex;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Backups → Restore (client, 2026-11-25). The database swap itself is the one the restore drill
 * and `kiln:restore-backup` use (BackupsTest); here it is replaced, so the test database stays.
 */
beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    Storage::fake('backups');
    $this->file = fakeBackup(now()->subDay()->format('Y-m-d-H-i-s'));
});

afterEach(function () {
    Artisan::call('up');
});

function restoreWith(array $input): array
{
    return app(WebRestore::class)->handle(test()->owner, $input + ['disk' => 'backups', 'file' => test()->file, 'password' => 'password1', 'phrase' => 'RESTORE']);
}

it('makes a safety backup, restores the chosen backup into the live database and checks the books', function () {
    $this->mock(BackupRestorer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('restore')->once()->withArgs(fn (array $backup, string $db, bool $files, bool $live) => $backup['file'] === test()->file && $files && $live)->andReturn(['tables' => 50]);
    });

    $result = restoreWith([]);

    expect($result['file'])->toBe($this->file)
        ->and($result['problems'])->toBe([])
        ->and(BackupRun::query()->where('status', BackupRun::SUCCEEDED)->count())->toBe(1)
        ->and($result['safety'])->toEndWith('.zip')
        ->and(app()->isDownForMaintenance())->toBeFalse()
        ->and(Activity::query()->where('event', 'backup_restored')->exists())->toBeTrue();
});

it('restores nothing without the password, the phrase or an existing backup', function () {
    $this->mock(BackupRestorer::class, fn (MockInterface $mock) => $mock->shouldNotReceive('restore'));

    expect(validationErrors(fn () => restoreWith(['password' => 'wrong'])))->toHaveKey('password')
        ->and(validationErrors(fn () => restoreWith(['phrase' => 'yes'])))->toHaveKey('phrase')
        ->and(validationErrors(fn () => restoreWith(['file' => 'nothing.zip'])))->toHaveKey('file')
        ->and(BackupRun::query()->count())->toBe(0);
});

it('restores nothing when the safety backup fails', function () {
    $this->mock(BackupRunner::class)->shouldReceive('run')->andReturn(new BackupRun(['status' => BackupRun::FAILED, 'message' => 'Disk full']));
    $this->mock(BackupRestorer::class, fn (MockInterface $mock) => $mock->shouldNotReceive('restore'));

    expect(validationErrors(fn () => restoreWith([])))->toHaveKey('file');
});

it('keeps the site closed when the restore fails', function () {
    $this->mock(BackupRestorer::class)->shouldReceive('restore')->andThrow(new RuntimeException('Broken dump'));

    $errors = validationErrors(fn () => restoreWith([]));

    expect($errors['file'])->toContain('closed for maintenance')
        ->and(app()->isDownForMaintenance())->toBeTrue();
});

it('is for the owner only', function () {
    $manager = makeUser($this->kiln, Role::Manager);
    $manager->givePermissionTo('backups.manage');
    actingInKiln($manager, $this->kiln);

    expect(fn () => app(WebRestore::class)->handle($manager, ['disk' => 'backups', 'file' => $this->file, 'password' => 'password1', 'phrase' => 'RESTORE']))
        ->toThrow(HttpException::class);
});

it('restores from the Backups page', function () {
    $this->mock(BackupRestorer::class)->shouldReceive('restore')->once()->andReturn(['tables' => 50]);

    $this->get('/backups')->assertSee('Restore');

    Livewire::test(BackupIndex::class)
        ->call('openRestore', 'backups', $this->file)
        ->set('password', 'password1')
        ->set('phrase', 'restore')
        ->call('runRestore')
        ->assertHasNoErrors()
        ->assertRedirect(route('backups.index'));
});
