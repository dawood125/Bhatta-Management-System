<?php

use App\Actions\Settings\SaveProduct;
use App\Enums\ProductKind;
use App\Enums\Role;
use App\Livewire\Settings\ProductIndex;
use App\Models\Activity;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->manager = makeUser($this->kiln, Role::Manager);
    actingInKiln($this->manager, $this->kiln);
    $this->brick = Unit::query()->where('code', 'brick')->sole();
    $this->trolley = Unit::query()->where('code', 'trolley')->sole();
});

function productInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Awal Barsati',
        'name_ur' => 'اوّل برساتی',
        'kind' => ProductKind::FiredGrade->value,
        'unit_id' => (string) Unit::query()->where('code', 'brick')->value('id'),
        'is_saleable' => true,
        'sort_order' => '75',
    ], $overrides);
}

it('lists the kiln\'s products', function () {
    Livewire::test(ProductIndex::class)
        ->assertSee('Awal')->assertSee('Khingar')->assertSee('Rora');
});

it('adds a product', function () {
    Livewire::test(ProductIndex::class)
        ->call('create')
        ->set('form', productInput(['name' => '  Awal   Barsati ']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('toast');

    $product = Product::query()->where('name', 'Awal Barsati')->sole();
    expect($product->kiln_id)->toBe($this->kiln->id)
        ->and($product->kind)->toBe(ProductKind::FiredGrade)
        ->and($product->unit_id)->toBe($this->brick->id)
        ->and($product->name_ur)->toBe('اوّل برساتی')
        ->and($product->sort_order)->toBe(75)
        ->and($product->is_active)->toBeTrue();

    expect(Activity::query()->where('subject_type', $product->getMorphClass())->where('subject_id', $product->id)->where('event', 'created')->sole()->kiln_id)
        ->toBe($this->kiln->id);
});

it('edits a product', function () {
    $doom = Product::query()->where('code', 'doom')->sole();

    Livewire::test(ProductIndex::class)
        ->call('edit', $doom->id)
        ->assertSet('form.name', 'Doom')
        ->set('form.name', 'Doom (second)')
        ->set('form.is_saleable', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($doom->fresh()->name)->toBe('Doom (second)')
        ->and($doom->fresh()->is_saleable)->toBeFalse();
});

it('validates product input', function (array $input, string $field) {
    Livewire::test(ProductIndex::class)
        ->set('form', productInput($input))
        ->call('save')
        ->assertHasErrors("form.{$field}");
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('x', 101)], 'name'],
    'duplicate name' => [['name' => 'Awal'], 'name'],
    'duplicate name, other letter case' => [['name' => 'AWAL'], 'name'],
    'duplicate Urdu name' => [['name_ur' => 'دوم'], 'name_ur'],
    'unknown type' => [['kind' => 'gold'], 'kind'],
    'missing unit' => [['unit_id' => ''], 'unit_id'],
    'unknown unit' => [['unit_id' => '999999'], 'unit_id'],
    'order out of range' => [['sort_order' => '10000'], 'sort_order'],
    'order not a number' => [['sort_order' => 'first'], 'sort_order'],
]);

it('requires brick grades and raw bricks to be counted in bricks', function (ProductKind $kind) {
    Livewire::test(ProductIndex::class)
        ->set('form', productInput(['kind' => $kind->value, 'unit_id' => (string) $this->trolley->id]))
        ->call('save')
        ->assertHasErrors('form.unit_id');
})->with([ProductKind::FiredGrade, ProductKind::RawBrick]);

it('allows any unit for by-products', function () {
    Livewire::test(ProductIndex::class)
        ->set('form', productInput(['name' => 'Ash', 'name_ur' => 'راکھ', 'kind' => ProductKind::ByProduct->value, 'unit_id' => (string) $this->trolley->id]))
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::query()->where('name', 'Ash')->sole()->unit_id)->toBe($this->trolley->id);
});

it('refuses an archived unit', function () {
    $ton = Unit::query()->where('code', 'ton')->sole();
    $ton->update(['is_active' => false]);

    Livewire::test(ProductIndex::class)
        ->set('form', productInput(['kind' => ProductKind::ByProduct->value, 'unit_id' => (string) $ton->id]))
        ->call('save')
        ->assertHasErrors('form.unit_id');
});

it('refuses a unit that belongs to another kiln', function () {
    $other = makeSeededKiln();
    $foreignUnit = app(CurrentKiln::class)->run($other, fn () => Unit::query()->where('code', 'ton')->sole());
    app(CurrentKiln::class)->set($this->kiln);

    Livewire::test(ProductIndex::class)
        ->set('form', productInput(['name' => 'Sneaky', 'kind' => ProductKind::ByProduct->value, 'unit_id' => (string) $foreignUnit->id]))
        ->call('save')
        ->assertHasErrors('form.unit_id');

    expect(Product::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('allows the same product name in a different kiln', function () {
    $other = makeSeededKiln();
    actingInKiln(makeUser($other, Role::Owner), $other);

    Livewire::test(ProductIndex::class)
        ->set('form', productInput(['name' => 'Awal Barsati']))
        ->call('save')
        ->assertHasNoErrors();

    actingInKiln($this->manager, $this->kiln);
    Livewire::test(ProductIndex::class)
        ->set('form', productInput(['name' => 'Awal Barsati']))
        ->call('save')
        ->assertHasNoErrors();
});

it('archives and restores a product instead of deleting it', function () {
    $tile = Product::query()->where('code', 'tile')->sole();

    Livewire::test(ProductIndex::class)
        ->call('archive', $tile->id)
        ->assertDispatched('toast')
        ->assertDontSee('Tile')
        ->set('status', 'archived')
        ->assertSee('Tile')
        ->call('restore', $tile->id)
        ->set('status', 'active')
        ->assertSee('Tile');

    expect(Product::query()->whereKey($tile->id)->exists())->toBeTrue()
        ->and($tile->fresh()->is_active)->toBeTrue();
});

it('treats a product of another kiln as not found', function (string $method) {
    $other = makeSeededKiln();
    $foreign = app(CurrentKiln::class)->run($other, fn () => Product::query()->where('code', 'awal')->sole());
    app(CurrentKiln::class)->set($this->kiln);

    Livewire::test(ProductIndex::class)->call($method, $foreign->id)->assertNotFound();

    app(CurrentKiln::class)->run($other, fn () => expect($foreign->fresh()->is_active)->toBeTrue());
})->with(['edit', 'archive', 'restore']);

it('refuses the save action itself for people without permission', function () {
    $viewer = makeUser($this->kiln, Role::Viewer);

    app(SaveProduct::class)->handle($viewer, null, productInput());
})->throws(AuthorizationException::class);
