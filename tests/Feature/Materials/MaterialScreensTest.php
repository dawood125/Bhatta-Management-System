<?php

use App\Actions\Materials\ReverseMaterialEntry;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Livewire\Materials\CostReport;
use App\Livewire\Materials\PurchaseForm;
use App\Livewire\Materials\PurchaseIndex;
use App\Livewire\Materials\PurchaseShow;
use App\Livewire\Materials\StockCard;
use App\Livewire\Materials\StockIndex;
use App\Livewire\Materials\UsageIndex;
use App\Livewire\Parties\PartyStatement;
use App\Livewire\Settings\MaterialIndex;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Unit;
use App\Support\JournalPresenter;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->supplier = makeParty($this->owner, 'Matti Supplier', [PartyRole::Supplier], extra: ['phone' => '0302 5551234']);
    $this->soil = material('soil');
    $this->coal = material('coal');
});

describe('access', function () {
    it('opens the screens for the right roles only', function (Role $role, int $view, int $record) {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);
        $user = makeUser($this->kiln, $role);

        foreach (['/materials', '/materials/purchases', "/materials/purchases/{$purchase->id}", '/materials/usage', '/materials/report', "/materials/{$this->soil->id}"] as $url) {
            $this->actingAs($user)->get($url)->assertStatus($view);
        }
        $this->actingAs($user)->get('/materials/purchases/new')->assertStatus($record);
    })->with([
        'owner' => [Role::Owner, 200, 200],
        'manager' => [Role::Manager, 200, 200],
        'munshi' => [Role::Munshi, 200, 200],
        'viewer' => [Role::Viewer, 200, 403],
        'data entry' => [Role::DataEntry, 403, 403],
    ]);

    it('returns 404 for another kiln\'s purchase and material', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        [$foreignPurchase, $foreignMaterial] = app(CurrentKiln::class)->run($other, function () use ($otherOwner) {
            $supplier = makeParty($otherOwner, 'Their supplier', [PartyRole::Supplier]);

            return [recordPurchase($otherOwner, $supplier, [[material('soil'), '1', '1']]), material('soil')];
        });
        app(CurrentKiln::class)->set($this->kiln);

        $this->actingAs($this->owner)->get("/materials/purchases/{$foreignPurchase->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/materials/{$foreignMaterial->id}")->assertNotFound();
    });

    it('shows the materials settings tab to settings managers only', function () {
        $this->actingAs($this->owner)->get('/settings/materials')->assertOk()->assertSee('Coal (Kola)');
        $this->actingAs(makeUser($this->kiln, Role::Munshi))->get('/settings/materials')->assertForbidden();
    });
});

describe('purchase form', function () {
    it('records the acceptance example through the screen and opens the purchase', function () {
        $component = Livewire::test(PurchaseForm::class)
            ->assertSet('form.payment', MaterialPurchase::ON_CREDIT)
            ->set('form.supplier_id', (string) $this->supplier->id)
            ->set('form.lines.0.material_id', (string) $this->soil->id)
            ->set('form.lines.0.quantity', '1,000')
            ->set('form.lines.0.rate', '50')
            ->assertSee('50,000.00')
            ->call('addCharge')
            ->set('form.charges.0.amount', '5,000')
            ->assertSee('55,000.00')     // total cost
            ->assertSee('Rs 55.00')      // landed cost per cubic foot
            ->set('form.reference', 'Slip 77')
            ->call('save')
            ->assertHasNoErrors();

        $purchase = MaterialPurchase::query()->sole();
        $component->assertRedirect(route('materials.purchases.show', $purchase));

        expect(stockOf($this->soil))->toBe(['1000.000', '55000.00'])
            ->and($purchase->reference)->toBe('Slip 77')
            ->and($this->supplier->balance()->toDecimal())->toBe('-55000.00');
    });

    it('records a purchase paid now, with a charge owed to the trolley driver', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
        $driver = makeParty($this->owner, 'Trolley Wala', [PartyRole::Driver]);

        Livewire::test(PurchaseForm::class)
            ->set('form.payment', MaterialPurchase::PAID_NOW)
            ->set('form.account_id', (string) $cash->id)
            ->set('form.lines.0.material_id', (string) $this->coal->id)
            ->set('form.lines.0.quantity', '1.25')
            ->set('form.lines.0.rate', '40000')
            ->call('addCharge')
            ->set('form.charges.0.amount', '2000')
            ->set('form.charges.0.payee', 'party')
            ->set('form.charges.0.party_id', (string) $driver->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(balanceOf($cash))->toBe('50000.00')
            ->and($driver->balance()->toDecimal())->toBe('-2000.00')
            ->and(stockOf($this->coal))->toBe(['1.250', '52000.00']);
    });

    it('shows errors on the right line and charge', function () {
        Livewire::test(PurchaseForm::class)
            ->set('form.lines.0.material_id', (string) $this->soil->id)
            ->set('form.lines.0.quantity', '10.555')
            ->set('form.lines.0.rate', '50')
            ->call('addCharge')
            ->set('form.charges.0.payee', 'account')
            ->set('form.charges.0.amount', '10')
            ->call('save')
            ->assertHasErrors(['form.supplier_id', 'form.charges.0.account_id']);

        Livewire::test(PurchaseForm::class)
            ->set('form.supplier_id', (string) $this->supplier->id)
            ->set('form.lines.0.material_id', (string) $this->soil->id)
            ->set('form.lines.0.quantity', '10.555')
            ->set('form.lines.0.rate', '50')
            ->call('save')
            ->assertHasErrors(['form.lines.0.quantity'])
            ->set('form.lines.0.quantity', '10.5')
            ->assertHasNoErrors();

        expect(MaterialPurchase::query()->count())->toBe(0);
    });

    it('sends the party list with the first render only', function () {
        $component = Livewire::test(PurchaseForm::class);
        expect($component->html())->toContain('id="purchase-party-options"')->toContain('Matti Supplier')->toContain('0302 5551234');

        $component->set('form.notes', 'x');
        expect($component->html())->not->toContain('id="purchase-party-options"');
    });
});

describe('purchases list and page', function () {
    it('totals the period, leaving out reversed purchases, and filters', function () {
        $soilBuy = recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '50']], [['transport', '500']], ['reference' => 'Slip 1']);
        $coalBuy = recordPurchase($this->owner, $this->supplier, [[$this->coal, '1', '40000']]);
        $wrong = recordPurchase($this->owner, $this->supplier, [[$this->soil, '7', '50']]);
        app(ReverseMaterialEntry::class)->handle($this->owner, $wrong->entry, ['reason' => 'Entered twice'], (string) Str::uuid());

        $component = Livewire::test(PurchaseIndex::class);
        $totals = $component->instance()->totals;

        expect($totals['goods']->toDecimal())->toBe('45000.00')
            ->and($totals['charges']->toDecimal())->toBe('500.00')
            ->and($totals['total']->toDecimal())->toBe('45500.00')
            ->and($totals['count'])->toBe(2);

        $component->assertSee('Reversed')
            ->set('material', (string) $this->coal->id)->assertSee($coalBuy->entry->number)->assertDontSee($soilBuy->entry->number)
            ->set('material', '')->set('search', 'Slip 1')->assertSee($soilBuy->entry->number)->assertDontSee($coalBuy->entry->number);
    });

    it('shows a purchase and reverses it from its page (owner and manager only)', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '1000', '50']], [['excavator', '5000', 'with_goods', null, 'Two hours']]);

        Livewire::test(PurchaseShow::class, ['purchase' => $purchase])
            ->assertSee('Material purchase')
            ->assertSee('Matti Supplier')
            ->assertSee('Excavator: Two hours')
            ->assertSee('Rs 55.00 per cft')
            ->call('confirmReverse', $purchase->journal_entry_id)
            ->set('reverseForm.reason', 'Wrong rate')
            ->call('reverse')
            ->assertHasNoErrors()
            ->assertSee('Wrong rate');

        expect(stockOf($this->soil))->toBe(['0.000', '0.00']);

        $other = recordPurchase($this->owner, $this->supplier, [[$this->soil, '1', '50']]);
        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);
        Livewire::test(PurchaseShow::class, ['purchase' => $other])->call('confirmReverse', $other->journal_entry_id)->assertForbidden();
    });

    it('describes the purchase on the supplier\'s statement', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);

        Livewire::test(PartyStatement::class, ['party' => $this->supplier])->assertSee('Material purchase: Matti Supplier');
    });
});

describe('usage', function () {
    beforeEach(function () {
        recordPurchase($this->owner, $this->supplier, [[$this->coal, '10', '40000'], [material('bora'), '100', '300']]);
    });

    it('records several materials from the dialog, opened from the stock page', function () {
        $this->get('/materials/usage?new=1')->assertOk();

        Livewire::withQueryParams(['new' => 1])->test(UsageIndex::class)
            ->assertSet('showForm', true)
            ->set('form.lines.0.material_id', (string) $this->coal->id)
            ->assertSee('10.000')                 // in stock
            ->set('form.lines.0.quantity', '2.5')
            ->assertSee('100,000.00')             // cost at average
            ->call('addLine')
            ->set('form.lines.1.material_id', (string) material('bora')->id)
            ->set('form.lines.1.quantity', '20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSee('106,000.00');

        expect(stockOf($this->coal)[0])->toBe('7.500')->and(stockOf(material('bora'))[0])->toBe('80.000');
    });

    it('refuses more than is in stock, on the right line', function () {
        Livewire::test(UsageIndex::class)
            ->call('create')
            ->set('form.lines.0.material_id', (string) $this->coal->id)
            ->set('form.lines.0.quantity', '10.001')
            ->call('save')
            ->assertHasErrors(['form.lines.0.quantity']);
    });

    it('reverses usage from the list (owner and manager only)', function () {
        $usage = recordUsage($this->owner, [[$this->coal, '1']]);

        Livewire::test(UsageIndex::class)
            ->call('confirmReverse', $usage->journal_entry_id)
            ->set('reverseForm.reason', 'Entered twice')
            ->call('reverse')
            ->assertHasNoErrors();

        expect(stockOf($this->coal)[0])->toBe('10.000');

        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);
        Livewire::test(UsageIndex::class)->call('confirmReverse', $usage->journal_entry_id)->assertForbidden();
    });

    it('describes usage by material in the expense accounts and cash book entries', function () {
        recordUsage($this->owner, [[$this->coal, '1']]);

        expect(JournalPresenter::describe(JournalEntry::query()->where('type', JournalType::MaterialUsage)->sole()))->toBe('Coal (Kola) used');
    });
});

describe('stock, stock card and report', function () {
    it('shows stock, average cost and value, and enters opening stock and shortages', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '1000', '50']], [['transport', '5000']]);

        Livewire::test(StockIndex::class)
            ->assertSee('1,000.00')       // in stock (cft has 2 decimals)
            ->assertSee('55.00')          // average cost per cft
            ->assertSee('55,000.00')      // value
            ->call('openAdjust', 'opening', $this->coal->id)
            ->set('adjustForm.quantity', '5')
            ->set('adjustForm.value', '200000')
            ->call('saveAdjust')
            ->assertHasNoErrors()
            ->call('openAdjust', 'shortage', $this->soil->id)
            ->set('adjustForm.quantity', '10')
            ->set('adjustForm.reason', 'Rain washed some away')
            ->call('saveAdjust')
            ->assertHasNoErrors()
            ->assertSee('254,450.00');    // 200,000 coal + 54,450 soil

        expect(stockOf($this->soil))->toBe(['990.000', '54450.00']);

        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);
        Livewire::test(StockIndex::class)->call('openAdjust', 'opening')->assertForbidden();
    });

    it('shows the stock card with a running balance and the period\'s opening stock', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '10']], extra: ['purchase_date' => now()->subDays(20)->format('Y-m-d')]);
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '50', '10']]);
        recordUsage($this->owner, [[$this->soil, '30', 'Pathai line 2']]);

        Livewire::test(StockCard::class, ['material' => $this->soil])
            ->set('from', now()->subDays(5)->format('Y-m-d'))
            ->assertSee('Stock brought forward')
            ->assertSee('100.00')     // brought forward
            ->assertSee('150.00')     // after today's purchase
            ->assertSee('120.00')     // after the usage
            ->assertSee('Pathai line 2');
    });

    it('reports bought, used and shortage per material for the period', function () {
        recordOpeningStock($this->owner, $this->coal, '2', '80000');
        recordPurchase($this->owner, $this->supplier, [[$this->coal, '3', '40000']], [['transport', '3000']]);
        recordUsage($this->owner, [[$this->coal, '1']]);
        recordShortage($this->owner, $this->coal, '0.5');

        $rows = Livewire::test(CostReport::class)->set('from', now()->subDays(10)->format('Y-m-d'))->instance()->rows;
        $coal = $rows->first(fn ($row) => $row['material']->id === $this->coal->id);

        // Opening stock was 30 days ago, so it is the stock at the start of the period.
        expect($coal['opening']['quantity']->toDecimal())->toBe('2.000')
            ->and($coal['bought']['value']->toDecimal())->toBe('123000.00')
            ->and($coal['used']['quantity']->toDecimal())->toBe('1.000')
            ->and($coal['used']['value']->toDecimal())->toBe('40600.00')     // 203,000 / 5 tons
            ->and($coal['shortage']['value']->toDecimal())->toBe('20300.00')
            ->and($coal['closing']['quantity']->toDecimal())->toBe('3.500')
            ->and($coal['closing']['value']->toDecimal())->toBe('142100.00');
    });
});

describe('settings', function () {
    it('adds and edits a material, and refuses a unit change once it has stock', function () {
        $maund = Unit::query()->where('code', 'maund')->sole();

        Livewire::test(MaterialIndex::class)
            ->call('create')
            ->set('form.name', 'Husk')
            ->set('form.name_ur', 'بھوسا')
            ->set('form.kind', 'fuel')
            ->set('form.unit_id', (string) $maund->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Husk');

        recordPurchase($this->owner, $this->supplier, [[$this->soil, '1', '50']]);

        Livewire::test(MaterialIndex::class)
            ->call('edit', $this->soil->id)
            ->set('form.unit_id', (string) $maund->id)
            ->call('save')
            ->assertHasErrors(['form.unit_id']);

        expect(Material::query()->where('name', 'Husk')->sole()->kind->value)->toBe('fuel');
    });
});
