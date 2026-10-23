<?php

use App\Actions\Settings\UpdateKilnProfile;
use App\Enums\Role;
use App\Enums\StageKind;
use App\Livewire\Settings\KilnProfile;
use App\Livewire\Settings\StageIndex;
use App\Models\ProductionStage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->kiln = makeSeededKiln(['name' => 'Old Name']);
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('production stages', function () {
    it('shows the four fixed stages in order with their meaning', function () {
        Livewire::test(StageIndex::class)
            ->assertSeeInOrder(['Raw bricks (Pathai)', 'In the kiln (Julai)', 'Fired, awaiting Nakaasi', 'Ready for sale'])
            ->assertSee(StageKind::FiredAwaitingUnloading->meaning());
    });

    it('renames a stage but never changes its kind', function () {
        $stage = ProductionStage::query()->where('kind', StageKind::Raw)->sole();

        Livewire::test(StageIndex::class)
            ->call('edit', $stage->id)
            ->set('form', ['name' => 'Kachi bricks', 'name_ur' => 'کچی', 'kind' => 'saleable'])
            ->call('save')
            ->assertHasNoErrors();

        $stage->refresh();
        expect($stage->name)->toBe('Kachi bricks')
            ->and($stage->name_ur)->toBe('کچی')
            ->and($stage->kind)->toBe(StageKind::Raw);
    });

    it('requires a stage name', function () {
        $stage = ProductionStage::query()->first();

        Livewire::test(StageIndex::class)
            ->call('edit', $stage->id)
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors('form.name');
    });
});

describe('kiln profile', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('updates the kiln details', function () {
        Livewire::test(KilnProfile::class)
            ->assertSet('form.name', 'Old Name')
            ->set('form.name', 'Al-Noor Bhatta')
            ->set('form.name_ur', 'النور بھٹہ')
            ->set('form.address', 'Main Road, Sheikhupura')
            ->set('form.phone', '0300 1234567')
            ->set('form.email', 'INFO@AlNoor.test')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.kiln'));

        $kiln = $this->kiln->fresh();
        expect($kiln->name)->toBe('Al-Noor Bhatta')
            ->and($kiln->name_ur)->toBe('النور بھٹہ')
            ->and($kiln->email)->toBe('info@alnoor.test');
    });

    it('validates the kiln details', function (string $field, string $value) {
        Livewire::test(KilnProfile::class)
            ->set("form.{$field}", $value)
            ->call('save')
            ->assertHasErrors("form.{$field}");
    })->with([
        ['name', ''],
        ['phone', 'call us'],
        ['email', 'not-an-email'],
        ['address', str_repeat('a', 256)],
    ]);

    it('uploads, replaces and removes the logo, cleaning up old files', function () {
        Livewire::test(KilnProfile::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('save')
            ->assertHasNoErrors();

        $first = $this->kiln->fresh()->logo_path;
        expect($first)->not->toBeNull();
        Storage::disk('public')->assertExists($first);

        Livewire::test(KilnProfile::class)
            ->set('logo', UploadedFile::fake()->image('new.jpg', 300, 300))
            ->call('save')
            ->assertHasNoErrors();

        $second = $this->kiln->fresh()->logo_path;
        expect($second)->not->toBe($first);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        Livewire::test(KilnProfile::class)
            ->set('removeLogo', true)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->kiln->fresh()->logo_path)->toBeNull();
        Storage::disk('public')->assertMissing($second);
    });

    it('refuses files that are not small images', function (UploadedFile $file) {
        Livewire::test(KilnProfile::class)
            ->set('logo', $file)
            ->call('save')
            ->assertHasErrors();

        expect($this->kiln->fresh()->logo_path)->toBeNull();
        expect(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'PDF' => fn () => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        'too large' => fn () => UploadedFile::fake()->image('big.png', 400, 400)->size(2048),
        'SVG (can carry scripts)' => fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    ]);

    it('is refused for managers, even when calling the action directly', function () {
        $manager = makeUser($this->kiln, Role::Manager);

        app(UpdateKilnProfile::class)->handle($manager, $this->kiln, ['name' => 'Hacked']);
    })->throws(AuthorizationException::class);

    it('cannot change a different kiln', function () {
        $other = makeKiln(['name' => 'Other Kiln']);

        expect(fn () => app(UpdateKilnProfile::class)->handle($this->owner, $other, ['name' => 'Hacked']))
            ->toThrow(NotFoundHttpException::class);

        expect($other->fresh()->name)->toBe('Other Kiln');
    });
});
