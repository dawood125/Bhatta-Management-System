<?php

use App\Enums\PartyRole;
use App\Enums\ProductionEntryKind;
use App\Enums\Role;
use App\Livewire\Materials\UsageIndex;
use App\Livewire\Production\DayView;
use App\Livewire\Production\EntryForms;
use App\Livewire\Production\EntryIndex;
use App\Livewire\Production\StockOverview;
use App\Models\ProductionEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('access', function () {
    it('shows production to every role, with buttons only for those who may record', function (Role $role, bool $record, bool $adjust) {
        $user = makeUser($this->kiln, $role);

        foreach (['/production', '/production/day', '/production/entries'] as $url) {
            $response = $this->actingAs($user)->get($url)->assertOk();
            // The buttons sit in a group labelled for screen readers (the entries filter lists the kinds for everyone).
            $record ? $response->assertSee('aria-label="Record production"', false) : $response->assertDontSee('aria-label="Record production"', false);
            $adjust ? $response->assertSee('Adjust stock') : $response->assertDontSee('Adjust stock');
        }
    })->with([
        'owner' => [Role::Owner, true, true],
        'manager' => [Role::Manager, true, true],
        'munshi' => [Role::Munshi, true, false],
        'data entry' => [Role::DataEntry, true, false],
        'viewer' => [Role::Viewer, false, false],
    ]);

    it('refuses the dialogs to roles without permission', function () {
        actingInKiln(makeUser($this->kiln, Role::Viewer), $this->kiln);
        Livewire::test(EntryForms::class)->call('open', 'pathai')->assertForbidden();

        actingInKiln(makeUser($this->kiln, Role::DataEntry), $this->kiln);
        Livewire::test(EntryForms::class)->call('open', 'pathai')->assertOk()->call('open', 'adjustment')->assertForbidden();
    });
});

describe('dialogs', function () {
    it('records Pathai from the dialog and tells the page to refresh', function () {
        Livewire::test(EntryForms::class)
            ->call('open', 'pathai')
            ->assertSet('showForm', true)
            ->set('form.team', 'Rafiq family')
            ->set('form.made', '12,000')
            ->set('form.rejected', '500')
            ->assertSee('11,500')                 // accepted, shown before saving
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertDispatched('production-recorded');

        expect(brickStock()['raw'])->toBe('11,500');
    });

    it('records loading, firing with fuel, and Nakaasi from the dialogs', function () {
        $supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]);
        recordPurchase($this->owner, $supplier, [[material('coal'), '5', '40000']]);
        recordPathai($this->owner, '3000');

        Livewire::test(EntryForms::class)
            ->call('open', 'loading')
            ->assertSee('3,000')                  // raw bricks available
            ->set('form.quantity', '3000')
            ->call('save')
            ->assertHasNoErrors()
            ->call('open', 'firing')
            ->set('form.quantity', '3000')
            ->call('addFuel')
            ->assertSet('form.fuel.0.material_id', (string) material('coal')->id)  // fuels are offered first
            ->set('form.fuel.0.quantity', '0.75')
            ->call('save')
            ->assertHasNoErrors()
            ->call('open', 'nakaasi')
            ->set('form.lines.0.quantity', '2500')  // Awal
            ->set('form.lines.1.quantity', '400')   // Doom
            ->set('form.lines.4.quantity', '100')   // Tooty
            ->assertSee('3,000')                    // bricks unloaded so far
            ->call('save')
            ->assertHasNoErrors();

        expect(brickStock())->toBe(['raw' => '0', 'in_firing' => '0', 'awaiting' => '0', 'saleable' => ['awal' => '2,500', 'doom' => '400', 'tooty' => '100']])
            ->and(stockOf(material('coal'))[0])->toBe('4.250');
    });

    it('shows errors on the right field and clears them when corrected', function () {
        recordPathai($this->owner, '100');

        Livewire::test(EntryForms::class)
            ->call('open', 'loading')
            ->set('form.quantity', '101')
            ->call('save')
            ->assertHasErrors(['form.quantity'])
            ->set('form.quantity', '100')
            ->assertHasNoErrors()
            ->call('open', 'nakaasi')
            ->set('form.lines.2.quantity', '7.5')
            ->call('save')
            ->assertHasErrors(['form.lines.2.quantity']);
    });

    it('records an adjustment with a reason (owner and manager)', function () {
        Livewire::test(EntryForms::class)
            ->call('open', 'adjustment')
            ->set('form.product_id', (string) product('awal')->id)
            ->set('form.direction', 'add')
            ->set('form.quantity', '250')
            ->set('form.reason', 'Counted more in the stack')
            ->call('save')
            ->assertHasNoErrors();

        expect(brickStock()['saleable'])->toBe(['awal' => '250']);
    });
});

describe('pages', function () {
    it('shows bricks at every stage and saleable stock by grade', function () {
        recordPathai($this->owner, '5000');
        recordLoading($this->owner, '3000');
        recordFiring($this->owner, '2000');
        recordNakaasi($this->owner, ['awal' => '1200', 'doom' => '300']);

        Livewire::test(StockOverview::class)
            ->assertSee('2,000')     // raw
            ->assertSee('1,000')     // in the kiln
            ->assertSee('500')       // awaiting Nakaasi
            ->assertSee('1,500')     // ready for sale (bricks)
            ->assertSee('1,200')     // Awal
            ->assertSee('Awal')
            ->dispatch('production-recorded')
            ->assertOk();
    });

    it('shows a day with its entries, flags possible duplicates, and reverses an entry', function () {
        recordPathai($this->owner, '1000', '0', ['team' => 'Team A']);
        $twice = recordPathai($this->owner, '1000', '0', ['team' => 'Team A']);

        Livewire::test(DayView::class)
            ->assertSee('Possible duplicate')
            ->assertSee('Worth checking')
            ->assertSee('Team A')
            ->call('confirmReverse', $twice->id)
            ->set('reverseForm.reason', 'Entered twice')
            ->call('reverse')
            ->assertHasNoErrors()
            ->assertDontSee('Possible duplicate')
            ->assertSee('Entered twice');

        expect(brickStock()['raw'])->toBe('1,000');

        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);
        Livewire::test(DayView::class)->call('confirmReverse', $twice->id)->assertForbidden();
    });

    it('moves between days and never into the future', function () {
        recordPathai($this->owner, '700', '0', ['entry_date' => now()->subDay()->format('Y-m-d')]);

        Livewire::test(DayView::class)
            ->assertSet('date', now()->format('Y-m-d'))
            ->assertSee('Nothing recorded on this day')
            ->call('previousDay')
            ->assertSet('date', now()->subDay()->format('Y-m-d'))
            ->assertSee('700')
            ->call('nextDay')
            ->call('nextDay')
            ->assertSet('date', now()->format('Y-m-d'));
    });

    it('lists entries for a period, filtered by kind and search', function () {
        $pathai = recordPathai($this->owner, '1000', '0', ['team' => 'Shafi team']);
        $loading = recordLoading($this->owner, '400');
        $old = recordPathai($this->owner, '50', '0', ['entry_date' => now()->subDays(40)->format('Y-m-d')]);

        Livewire::test(EntryIndex::class)
            ->set('from', now()->subDays(5)->format('Y-m-d'))
            ->assertSee($pathai->number)->assertSee($loading->number)->assertDontSee($old->number)
            ->set('kind', ProductionEntryKind::Loading->value)->assertSee($loading->number)->assertDontSee($pathai->number)
            ->set('kind', '')->set('search', 'Shafi')->assertSee($pathai->number)->assertDontSee($loading->number);
    });

    it('marks fuel recorded with firing on the materials usage list, without its own reverse button', function () {
        $supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]);
        recordPurchase($this->owner, $supplier, [[material('coal'), '5', '40000']]);
        recordPathai($this->owner, '1000');
        recordLoading($this->owner, '1000');
        $firing = recordFiring($this->owner, '1000', [[material('coal'), '1']]);

        Livewire::test(UsageIndex::class)
            ->assertSee("Firing {$firing->number}")
            ->assertDontSeeHtml('wire:click="confirmReverse('.$firing->materialUsage->journal_entry_id.')"');

        expect(ProductionEntry::query()->count())->toBe(3);
    });
});
