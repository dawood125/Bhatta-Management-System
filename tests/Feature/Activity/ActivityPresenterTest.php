<?php

use App\Enums\Role;
use App\Models\Activity;
use App\Models\Area;
use App\Models\Product;
use App\Support\ActivityPresenter;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    actingInKiln(makeUser($this->kiln, Role::Owner), $this->kiln);
});

it('describes settings records by type and name', function () {
    $area = Area::query()->create(['name' => 'Kasur', 'name_ur' => 'قصور', 'is_active' => true]);
    $entry = Activity::query()->where('subject_type', $area->getMorphClass())->where('subject_id', $area->id)->latest('id')->first();

    expect(ActivityPresenter::subjectLabel($entry))->toBe("Area: \u{2068}Kasur\u{2069}");

    app()->setLocale('ur');
    expect(ActivityPresenter::subjectLabel($entry))->toBe("علاقہ: \u{2068}قصور\u{2069}");
});

it('says "Archived" for lists and translates product fields', function () {
    $tile = Product::query()->where('code', 'tile')->sole();
    $tile->update(['is_active' => false, 'is_saleable' => false]);

    $entry = Activity::query()->where('subject_type', $tile->getMorphClass())->where('subject_id', $tile->id)->where('event', 'updated')->latest('id')->first();
    $changes = collect(ActivityPresenter::changes($entry))->keyBy('field');

    expect($changes['Status']['new'])->toBe('Archived')
        ->and($changes['For sale']['old'])->toBe('Yes')
        ->and($changes['For sale']['new'])->toBe('No');
});
