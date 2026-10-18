<?php

use App\Enums\Role;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->kiln = makeKiln();
    $this->user = makeUser($this->kiln, Role::Owner, ['locale' => 'en']);
});

it('lets a guest switch the login page to Urdu (right-to-left)', function () {
    $this->from('/login')->post('/locale', ['locale' => 'ur'])->assertRedirect('/login');

    $this->get('/login')
        ->assertOk()
        ->assertSee('<html lang="ur" dir="rtl">', false)
        ->assertSee('صارف نام یا ای میل');
});

it('saves the language on the user so it follows them to every device', function () {
    $this->actingAs($this->user)->from('/dashboard')->post('/locale', ['locale' => 'ur'])->assertRedirect('/dashboard');

    expect($this->user->fresh()->locale)->toBe('ur');

    $this->get('/dashboard')
        ->assertSee('<html lang="ur" dir="rtl">', false)
        ->assertSee('ڈیش بورڈ');
});

it('uses the user\'s saved language over the session', function () {
    $this->user->update(['locale' => 'ur']);

    $this->actingAs($this->user)->withSession(['locale' => 'en'])->get('/dashboard')
        ->assertSee('<html lang="ur" dir="rtl">', false);
});

it('rejects unknown languages', function () {
    $this->from('/login')->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
});

it('shows Urdu validation messages in Urdu', function () {
    $this->post('/locale', ['locale' => 'ur']);

    $this->from('/login')->post('/login', ['login' => 'nobody', 'password' => 'wrongpass1'])
        ->assertSessionHasErrors(['login' => 'صارف نام یا پاس ورڈ درست نہیں ہے۔']);
});

/**
 * Every text shown through __('...') must have an Urdu translation, and every :placeholder
 * must survive translation. This test fails the build if someone adds an untranslated label.
 */
it('has an Urdu translation for every interface text', function () {
    $urdu = json_decode(File::get(lang_path('ur.json')), true, flags: JSON_THROW_ON_ERROR);

    $files = collect([app_path(), resource_path('views')])
        ->flatMap(fn (string $dir) => File::allFiles($dir))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'));

    $missing = [];
    foreach ($files as $file) {
        preg_match_all('/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $file->getContents(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = str_replace('\\'.$match[1], $match[1], $match[2]);
            // Keys like "auth.failed" live in the PHP language files, not in ur.json.
            if (preg_match('/^[a-z_]+\.[a-z_.]+$/', $key)) {
                continue;
            }
            if (! array_key_exists($key, $urdu)) {
                $missing[$key] = $file->getRelativePathname();
            }
        }
    }

    expect($missing)->toBe([]);
});

it('keeps every :placeholder in the Urdu translations', function () {
    $urdu = json_decode(File::get(lang_path('ur.json')), true, flags: JSON_THROW_ON_ERROR);

    $broken = [];
    foreach ($urdu as $english => $translation) {
        preg_match_all('/:([a-z_]+)/', $english, $placeholders);
        foreach ($placeholders[1] as $placeholder) {
            if (! str_contains($translation, ':'.$placeholder)) {
                $broken[$english] = $placeholder;
            }
        }
        expect(trim($translation))->not->toBe('');
    }

    expect($broken)->toBe([]);
});

it('has Urdu versions of the standard language files', function (string $file) {
    expect(File::exists(lang_path("ur/{$file}.php")))->toBeTrue();

    app()->setLocale('ur');
    expect(__("{$file}.".match ($file) {
        'validation' => 'required',
        'auth' => 'failed',
        'pagination' => 'next',
        'passwords' => 'reset',
    }))->not->toStartWith($file.'.');
})->with(['validation', 'auth', 'pagination', 'passwords']);
