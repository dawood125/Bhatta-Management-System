<?php

use App\Enums\PartyRole;
use App\Enums\Role;
use App\Livewire\Materials\PurchaseForm;
use App\Livewire\Parties\QuickAddParty;
use App\Livewire\Sales\SaleForm;
use App\Models\Party;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

it('shows New customer on the sale page and New supplier on the purchase page', function () {
    $this->get('/sales/new')->assertOk()->assertSee('New customer')->assertSeeLivewire(QuickAddParty::class);
    $this->get('/materials/purchases/new')->assertOk()->assertSee('New supplier')->assertSeeLivewire(QuickAddParty::class);
});

it('adds a customer, puts it in the picker and tells the page to choose it', function () {
    Livewire::test(QuickAddParty::class, ['role' => 'customer', 'source' => 'sale-customer-options'])
        ->call('open')
        ->assertSet('show', true)
        ->set('form.name', 'Naveed Builders')
        ->set('form.phone', '03001112233')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('combobox-add', fn ($name, $params) => $params['source'] === 'sale-customer-options' && $params['option']['label'] === 'Naveed Builders' && $params['option']['hint'] === '03001112233')
        ->assertDispatched('quick-party-added', role: 'customer');

    $party = Party::query()->where('name', 'Naveed Builders')->sole();
    expect($party->roles())->toBe([PartyRole::Customer])
        ->and($party->opening_balance->isZero())->toBeTrue();
});

it('records an opening balance in the right direction', function () {
    Livewire::test(QuickAddParty::class, ['role' => 'supplier', 'source' => 'purchase-party-options'])
        ->call('open')
        ->assertSet('form.opening_direction', 'payable')
        ->set('form.name', 'Rafiq Coal')
        ->set('form.opening_amount', '40,000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('quick-party-added', role: 'supplier');

    $party = Party::query()->where('name', 'Rafiq Coal')->sole();
    expect($party->roles())->toBe([PartyRole::Supplier])
        ->and($party->opening_balance->toDecimal())->toBe('-40000.00')
        ->and($party->opening_entry_id)->not->toBeNull();
});

it('refuses a name that already exists and warns about similar ones', function () {
    makeParty($this->owner, 'Ali Traders');

    Livewire::test(QuickAddParty::class, ['role' => 'customer', 'source' => 'sale-customer-options'])
        ->call('open')
        ->set('form.name', 'Ali Trad')
        ->assertSee('Similar names already exist')
        ->set('form.name', 'Ali Traders')
        ->call('save')
        ->assertHasErrors('form.name')
        ->assertSet('show', true);

    expect(Party::query()->where('name', 'Ali Traders')->count())->toBe(1);
});

it('needs a name and checks the phone', function () {
    Livewire::test(QuickAddParty::class, ['role' => 'customer', 'source' => 'sale-customer-options'])
        ->call('open')
        ->set('form.phone', 'abc')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.phone']);

    expect(Party::query()->count())->toBe(0);
});

it('saves once when the button is pressed twice', function () {
    $component = Livewire::test(QuickAddParty::class, ['role' => 'customer', 'source' => 'sale-customer-options'])
        ->call('open')
        ->set('form.name', 'Double Click');
    $key = $component->get('formKey');
    $component->call('save');

    // The same form key again (a double submit that slipped past the closed dialog).
    $component->set('formKey', $key)->set('form.name', 'Double Click 2')->call('save');

    expect(Party::query()->count())->toBe(1);
});

it('chooses the new customer in the sale and the new supplier in the purchase', function () {
    $customer = makeParty($this->owner, 'Fresh Customer');
    $supplier = makeParty($this->owner, 'Fresh Supplier', [PartyRole::Supplier]);

    Livewire::test(SaleForm::class)
        ->dispatch('quick-party-added', role: 'customer', id: $customer->id)
        ->assertSet('form.customer_id', (string) $customer->id)
        ->dispatch('quick-party-added', role: 'supplier', id: $supplier->id)
        ->assertSet('form.customer_id', (string) $customer->id);

    Livewire::test(PurchaseForm::class)
        ->dispatch('quick-party-added', role: 'supplier', id: $supplier->id)
        ->assertSet('form.supplier_id', (string) $supplier->id)
        ->dispatch('quick-party-added', role: 'customer', id: $customer->id)
        ->assertSet('form.supplier_id', (string) $supplier->id);
});

it('is not available to users who may not add parties', function () {
    actingInKiln(makeUser($this->kiln, Role::Viewer), $this->kiln);

    Livewire::test(QuickAddParty::class, ['role' => 'customer', 'source' => 'sale-customer-options'])->assertForbidden();
});
