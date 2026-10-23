<?php

use App\Enums\ProductKind;
use App\Enums\Role;
use App\Enums\StageKind;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\Kiln;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\Unit;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\KilnDefaults;

function countsIn($kiln): array
{
    return app(CurrentKiln::class)->run($kiln, fn () => [
        'units' => Unit::query()->count(),
        'products' => Product::query()->count(),
        'stages' => ProductionStage::query()->count(),
        'categories' => ExpenseCategory::query()->count(),
        'subs' => ExpenseSubCategory::query()->count(),
    ]);
}

it('gives a new kiln the default units, grades, stages and expense categories', function () {
    $kiln = makeSeededKiln();

    expect(countsIn($kiln))->toBe([
        'units' => count(KilnDefaults::UNITS),
        'products' => count(KilnDefaults::PRODUCTS),
        'stages' => count(StageKind::cases()),
        'categories' => count(KilnDefaults::EXPENSE_CATEGORIES),
        'subs' => collect(KilnDefaults::EXPENSE_CATEGORIES)->sum(fn ($c) => count($c['subs'])),
    ]);

    app(CurrentKiln::class)->run($kiln, function () {
        $brick = Unit::query()->where('code', 'brick')->sole();
        expect($brick->is_system)->toBeTrue()->and($brick->decimals)->toBe(0);

        $awal = Product::query()->where('code', 'awal')->sole();
        expect($awal->kind)->toBe(ProductKind::FiredGrade)
            ->and($awal->unit_id)->toBe($brick->id)
            ->and($awal->name_ur)->toBe('اوّل')
            ->and($awal->is_saleable)->toBeTrue();

        expect(Product::query()->where('code', 'kachi')->sole()->is_saleable)->toBeFalse();

        expect(ProductionStage::query()->orderBy('sort_order')->get()->map(fn ($s) => $s->kind)->all())
            ->toBe([StageKind::Raw, StageKind::InFiring, StageKind::FiredAwaitingUnloading, StageKind::Saleable]);
    });
});

it('never duplicates or changes existing rows when run again', function () {
    $kiln = makeSeededKiln();
    $before = countsIn($kiln);

    app(CurrentKiln::class)->run($kiln, fn () => Product::query()->where('code', 'awal')->sole()->update(['name' => 'Awal Special']));

    app(KilnDefaults::class)->seed($kiln);
    $this->artisan('kiln:defaults')->assertSuccessful();

    expect(countsIn($kiln))->toBe($before);
    app(CurrentKiln::class)->run($kiln, fn () => expect(Product::query()->where('code', 'awal')->sole()->name)->toBe('Awal Special'));
});

it('keeps each kiln\'s defaults separate', function () {
    $kilnA = makeSeededKiln();
    $kilnB = makeSeededKiln();

    app(CurrentKiln::class)->run($kilnA, fn () => Product::query()->where('code', 'awal')->sole()->update(['name' => 'A grade']));

    app(CurrentKiln::class)->run($kilnB, function () {
        expect(Product::query()->where('code', 'awal')->sole()->name)->toBe('Awal')
            ->and(Product::query()->count())->toBe(count(KilnDefaults::PRODUCTS));
    });
});

it('is applied by kiln:install', function () {
    $this->artisan('kiln:install', [
        '--kiln' => 'Fresh Kiln', '--owner' => 'Owner', '--username' => 'freshowner', '--password' => 'strongpass1', '--no-interaction' => true,
    ])->assertSuccessful();

    $kiln = Kiln::query()->where('name', 'Fresh Kiln')->sole();
    expect(countsIn($kiln)['products'])->toBe(count(KilnDefaults::PRODUCTS));
});

it('shows names in the user\'s language', function () {
    $kiln = makeSeededKiln();
    actingInKiln(makeUser($kiln, Role::Viewer), $kiln);
    $awal = Product::query()->where('code', 'awal')->sole();

    app()->setLocale('en');
    expect($awal->displayName())->toBe('Awal');

    app()->setLocale('ur');
    expect($awal->displayName())->toBe('اوّل');

    $awal->name_ur = null;
    expect($awal->displayName())->toBe('Awal');
});
