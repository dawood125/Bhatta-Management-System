<?php

use App\Enums\ProductKind;
use App\Enums\Role;
use App\Livewire\Settings\UnitIndex;
use App\Models\Product;
use App\Models\Unit;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    actingInKiln(makeUser($this->kiln, Role::Owner), $this->kiln);
});

it('adds and edits a unit', function () {
    Livewire::test(UnitIndex::class)
        ->call('create')
        ->set('form', ['name' => 'Truck', 'name_ur' => 'ٹرک', 'symbol' => 'truck', 'decimals' => '0'])
        ->call('save')
        ->assertHasNoErrors();

    $truck = Unit::query()->where('name', 'Truck')->sole();
    expect($truck->is_system)->toBeFalse()->and($truck->code)->toBeNull();

    Livewire::test(UnitIndex::class)
        ->call('edit', $truck->id)
        ->set('form.decimals', '2')
        ->call('save')
        ->assertHasNoErrors();

    expect($truck->fresh()->decimals)->toBe(2);
});

it('validates unit input', function (array $input, string $field) {
    Livewire::test(UnitIndex::class)
        ->set('form', array_merge(['name' => 'Truck', 'name_ur' => '', 'symbol' => '', 'decimals' => '0'], $input))
        ->call('save')
        ->assertHasErrors("form.{$field}");
})->with([
    'missing name' => [['name' => ''], 'name'],
    'duplicate name' => [['name' => 'Ton'], 'name'],
    'duplicate Urdu name' => [['name_ur' => 'ٹن'], 'name_ur'],
    'too many decimals' => [['decimals' => '4'], 'decimals'],
    'negative decimals' => [['decimals' => '-1'], 'decimals'],
    'symbol too long' => [['symbol' => str_repeat('s', 21)], 'symbol'],
]);

it('lets the built-in brick unit be renamed but not change its decimals', function () {
    $brick = Unit::query()->where('code', 'brick')->sole();

    Livewire::test(UnitIndex::class)
        ->call('edit', $brick->id)
        ->set('form.name', 'Bricks (pcs)')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(UnitIndex::class)
        ->call('edit', $brick->id)
        ->set('form.decimals', '2')
        ->call('save')
        ->assertHasErrors('form.decimals');

    expect($brick->fresh()->name)->toBe('Bricks (pcs)')
        ->and($brick->fresh()->decimals)->toBe(0);
});

it('never archives the built-in brick unit', function () {
    $brick = Unit::query()->where('code', 'brick')->sole();

    Livewire::test(UnitIndex::class)
        ->call('archive', $brick->id)
        ->assertDispatched('toast', type: 'error');

    expect($brick->fresh()->is_active)->toBeTrue();
});

it('does not archive a unit that active products use', function () {
    $trolley = Unit::query()->where('code', 'trolley')->sole(); // Rora uses it

    Livewire::test(UnitIndex::class)->call('archive', $trolley->id)->assertDispatched('toast', type: 'error');
    expect($trolley->fresh()->is_active)->toBeTrue();

    Product::query()->where('code', 'rora')->update(['is_active' => false]);

    Livewire::test(UnitIndex::class)->call('archive', $trolley->id)->assertDispatched('toast', type: 'success');
    expect($trolley->fresh()->is_active)->toBeFalse();
});

it('archives an unused unit and restores it', function () {
    $litre = Unit::query()->where('code', 'litre')->sole();

    Livewire::test(UnitIndex::class)->call('archive', $litre->id);
    expect($litre->fresh()->is_active)->toBeFalse();

    Livewire::test(UnitIndex::class)->call('restore', $litre->id);
    expect($litre->fresh()->is_active)->toBeTrue();
});

it('keeps product kinds valid for every default product', function () {
    $brickId = Unit::query()->where('code', 'brick')->value('id');

    foreach (Product::query()->get() as $product) {
        if ($product->kind !== ProductKind::ByProduct) {
            expect($product->unit_id)->toBe($brickId);
        }
    }
});
