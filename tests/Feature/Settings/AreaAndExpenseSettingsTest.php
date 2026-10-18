<?php

use App\Enums\Role;
use App\Livewire\Settings\AreaIndex;
use App\Livewire\Settings\ExpenseCategoryIndex;
use App\Models\Area;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Support\Kiln\CurrentKiln;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    actingInKiln(makeUser($this->kiln, Role::Manager), $this->kiln);
});

describe('areas', function () {
    it('starts empty and lets you add, edit, archive and restore areas', function () {
        Livewire::test(AreaIndex::class)
            ->assertSee('No areas yet')
            ->call('create')
            ->set('form', ['name' => 'Sheikhupura', 'name_ur' => 'شیخوپورہ'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Sheikhupura');

        $area = Area::query()->sole();

        Livewire::test(AreaIndex::class)
            ->call('edit', $area->id)
            ->set('form.name', 'Sheikhupura City')
            ->call('save')
            ->assertHasNoErrors()
            ->call('archive', $area->id)
            ->assertDontSee('Sheikhupura City')
            ->call('restore', $area->id);

        expect($area->fresh()->name)->toBe('Sheikhupura City')
            ->and($area->fresh()->is_active)->toBeTrue();
    });

    it('rejects duplicate and empty area names', function (array $form, string $field) {
        Area::query()->create(['name' => 'Lahore', 'name_ur' => 'لاہور', 'is_active' => true]);

        Livewire::test(AreaIndex::class)
            ->set('form', $form)
            ->call('save')
            ->assertHasErrors("form.{$field}");
    })->with([
        'empty' => [['name' => '', 'name_ur' => ''], 'name'],
        'duplicate' => [['name' => 'lahore', 'name_ur' => ''], 'name'],
        'duplicate Urdu' => [['name' => 'Lahore Cantt', 'name_ur' => 'لاہور'], 'name_ur'],
    ]);

    it('searches in English and Urdu', function () {
        Area::query()->create(['name' => 'Lahore', 'name_ur' => 'لاہور', 'is_active' => true]);
        Area::query()->create(['name' => 'Kasur', 'name_ur' => 'قصور', 'is_active' => true]);

        Livewire::test(AreaIndex::class)
            ->set('search', 'قصور')->assertSee('Kasur')->assertDontSee('Lahore')
            ->set('search', 'lah')->assertSee('Lahore')->assertDontSee('Kasur');
    });
});

describe('expense categories', function () {
    it('adds a category and a sub-category', function () {
        Livewire::test(ExpenseCategoryIndex::class)
            ->call('create')
            ->set('form', ['name' => 'Security', 'name_ur' => 'سیکیورٹی', 'sort_order' => '80'])
            ->call('save')
            ->assertHasNoErrors();

        $category = ExpenseCategory::query()->where('name', 'Security')->sole();

        Livewire::test(ExpenseCategoryIndex::class)
            ->call('createSub', $category->id)
            ->assertSet('subForm.expense_category_id', (string) $category->id)
            ->set('subForm.name', 'Guard wages')
            ->set('subForm.name_ur', 'چوکیدار کی تنخواہ')
            ->call('saveSub')
            ->assertHasNoErrors()
            ->assertSee('Guard wages');

        expect($category->subCategories()->sole()->name)->toBe('Guard wages');
    });

    it('allows the same sub-category name under different categories, but not twice in one', function () {
        $fuel = ExpenseCategory::query()->where('name', 'Fuel and vehicles')->sole();
        $office = ExpenseCategory::query()->where('name', 'Office')->sole();

        Livewire::test(ExpenseCategoryIndex::class)
            ->call('createSub', $office->id)
            ->set('subForm.name', 'Diesel')
            ->call('saveSub')
            ->assertHasNoErrors();

        Livewire::test(ExpenseCategoryIndex::class)
            ->call('createSub', $fuel->id)
            ->set('subForm.name', 'diesel')
            ->call('saveSub')
            ->assertHasErrors('subForm.name');
    });

    it('refuses a sub-category under another kiln\'s category', function () {
        $other = makeSeededKiln();
        $foreign = app(CurrentKiln::class)->run($other, fn () => ExpenseCategory::query()->where('name', 'Office')->sole());
        app(CurrentKiln::class)->set($this->kiln);

        Livewire::test(ExpenseCategoryIndex::class)->call('createSub', $foreign->id)->assertNotFound();

        Livewire::test(ExpenseCategoryIndex::class)
            ->set('subForm', ['expense_category_id' => (string) $foreign->id, 'name' => 'Sneaky', 'name_ur' => ''])
            ->call('saveSub')
            ->assertHasErrors('subForm.expense_category_id');

        expect(ExpenseSubCategory::withoutGlobalScopes()->where('name', 'Sneaky')->exists())->toBeFalse();
    });

    it('archives and restores sub-categories', function () {
        $diesel = ExpenseSubCategory::query()->where('name', 'Diesel')->sole();

        Livewire::test(ExpenseCategoryIndex::class)->call('archiveSub', $diesel->id)->assertDispatched('toast');
        expect($diesel->fresh()->is_active)->toBeFalse();

        Livewire::test(ExpenseCategoryIndex::class)->call('restoreSub', $diesel->id);
        expect($diesel->fresh()->is_active)->toBeTrue();
    });
});
