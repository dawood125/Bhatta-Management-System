<?php

use App\Accounting\LedgerVerifier;
use App\Actions\Parties\SaveParty;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Livewire\Parties\PartyIndex;
use App\Livewire\Parties\PartyStatement;
use App\Models\Activity;
use App\Models\Area;
use App\Models\Party;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('saving parties', function () {
    it('stores details and roles, and logs the roles', function () {
        $area = Area::query()->create(['name' => 'Sheikhupura', 'is_active' => true]);

        $party = makeParty($this->owner, 'Rana Traders', [PartyRole::Customer, PartyRole::Supplier], extra: [
            'name_ur' => 'رانا ٹریڈرز', 'phone' => '0300 1234567', 'area_id' => (string) $area->id,
        ]);

        expect($party->roles())->toBe([PartyRole::Customer, PartyRole::Supplier])
            ->and($party->area_id)->toBe($area->id)
            ->and($party->name_ur)->toBe('رانا ٹریڈرز')
            ->and(Activity::query()->where('event', 'roles_changed')->where('subject_id', $party->id)->exists())->toBeTrue();
    });

    it('validates party details', function (array $input, string $field) {
        try {
            makeParty($this->owner, $input['name'] ?? 'Someone', extra: $input);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    })->with([
        'no roles' => [['roles' => []], 'roles'],
        'unknown role' => [['roles' => ['partner']], 'roles.0'],
        'empty name' => [['name' => ''], 'name'],
        'bad phone' => [['phone' => 'call me'], 'phone'],
        'bad amount' => [['opening_amount' => '-50'], 'opening_amount'],
        'bad direction' => [['opening_direction' => 'sideways'], 'opening_direction'],
        'future date' => [fn () => ['opening_date' => now()->addDay()->format('Y-m-d')], 'opening_date'],
        'unknown area' => [['area_id' => '999999'], 'area_id'],
    ]);

    it('refuses an area of another kiln', function () {
        $other = makeSeededKiln();
        $foreignArea = app(CurrentKiln::class)->run($other, fn () => Area::query()->create(['name' => 'Their area', 'is_active' => true]));
        app(CurrentKiln::class)->set($this->kiln);

        makeParty($this->owner, 'Customer', extra: ['area_id' => (string) $foreignArea->id]);
    })->throws(ValidationException::class);

    it('links a customer to a sales agent', function () {
        $agent = makeParty($this->owner, 'Agent Javed', [PartyRole::Agent]);
        $customer = makeParty($this->owner, 'Customer', extra: ['sales_agent_id' => (string) $agent->id]);

        expect($customer->sales_agent_id)->toBe($agent->id);
    });

    it('accepts only real sales agents, and never the party itself', function () {
        $notAgent = makeParty($this->owner, 'Plain supplier', [PartyRole::Supplier]);
        expect(fn () => makeParty($this->owner, 'Customer', extra: ['sales_agent_id' => (string) $notAgent->id]))->toThrow(ValidationException::class);

        $both = makeParty($this->owner, 'Agent and customer', [PartyRole::Agent, PartyRole::Customer]);
        expect(fn () => updateParty($this->owner, $both, ['sales_agent_id' => (string) $both->id]))->toThrow(ValidationException::class);
    });

    it('drops the sales agent for parties that are not customers', function () {
        $agent = makeParty($this->owner, 'Agent', [PartyRole::Agent]);
        $supplier = makeParty($this->owner, 'Supplier', [PartyRole::Supplier], extra: ['sales_agent_id' => (string) $agent->id]);

        expect($supplier->sales_agent_id)->toBeNull();
    });

    it('does not remove the agent role while customers still use that agent', function () {
        $agent = makeParty($this->owner, 'Agent', [PartyRole::Agent]);
        makeParty($this->owner, 'Customer', extra: ['sales_agent_id' => (string) $agent->id]);

        updateParty($this->owner, $agent, ['roles' => [PartyRole::Customer->value]]);
    })->throws(ValidationException::class);

    it('handles a double submit of a new party opening balance without posting twice', function () {
        $key = (string) Str::uuid();
        $input = [
            'roles' => ['customer'], 'name' => 'Double', 'name_ur' => '', 'phone' => '', 'phone_2' => '', 'address' => '',
            'area_id' => '', 'sales_agent_id' => '', 'notes' => '', 'opening_amount' => '500',
            'opening_direction' => SaveParty::THEY_OWE_US, 'opening_date' => now()->format('Y-m-d'),
        ];

        $first = app(SaveParty::class)->handle($this->owner, null, $input, $key);
        $second = app(SaveParty::class)->handle($this->owner, null, $input, $key);

        expect($second->id)->toBe($first->id)
            ->and(Party::query()->where('name', 'Double')->count())->toBe(1)
            ->and($first->balance()->toDecimal())->toBe('500.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('still allows two different people with the same name (different forms)', function () {
        makeParty($this->owner, 'Muhammad Ali');
        makeParty($this->owner, 'Muhammad Ali');

        expect(Party::query()->where('name', 'Muhammad Ali')->count())->toBe(2);
    });

    it('is refused for roles without permission, even when calling the action directly', function () {
        makeParty(makeUser($this->kiln, Role::Viewer), 'Customer');
    })->throws(AuthorizationException::class);
});

describe('screens', function () {
    it('opens for the right roles only', function (Role $role, int $list, bool $canManage) {
        $party = makeParty($this->owner, 'Customer '.$role->value);
        $user = makeUser($this->kiln, $role);

        $this->actingAs($user)->get('/parties')->assertStatus($list);
        $this->actingAs($user)->get("/parties/{$party->id}")->assertStatus($list);

        if ($list === 200) {
            actingInKiln($user, $this->kiln);
            $component = Livewire::test(PartyIndex::class)->call('create');
            $canManage ? $component->assertOk() : $component->assertForbidden();
        }
    })->with([
        'owner' => [Role::Owner, 200, true],
        'manager' => [Role::Manager, 200, true],
        'munshi' => [Role::Munshi, 200, true],
        'viewer' => [Role::Viewer, 200, false],
        'data entry' => [Role::DataEntry, 403, false],
    ]);

    it('returns 404 for a statement of another kiln\'s party', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        $foreign = app(CurrentKiln::class)->run($other, fn () => makeParty($otherOwner, 'Their customer', [PartyRole::Customer], '999'));

        $this->actingAs($this->owner)->get("/parties/{$foreign->id}")->assertNotFound();
    });

    it('adds a party from the screen and warns about similar names', function () {
        makeParty($this->owner, 'Muhammad Akram', extra: ['phone' => '0301 1111111']);

        Livewire::test(PartyIndex::class)
            ->call('create')
            ->set('form.name', 'Muhammad Akram')
            ->assertSee('Similar names already exist')
            ->set('form.name', 'Muhammad Akram Sons')
            ->set('form.opening_amount', '25,000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Muhammad Akram Sons');

        expect(Party::query()->where('name', 'Muhammad Akram Sons')->sole()->balance()->toDecimal())->toBe('25000.00');
    });

    it('filters by type, area and search', function () {
        $area = Area::query()->create(['name' => 'Kasur', 'is_active' => true]);
        makeParty($this->owner, 'Customer One', extra: ['area_id' => (string) $area->id]);
        makeParty($this->owner, 'Supplier One', [PartyRole::Supplier], extra: ['phone' => '0333 7654321']);

        Livewire::test(PartyIndex::class)
            ->set('role', 'supplier')->assertSee('Supplier One')->assertDontSee('Customer One')
            ->set('role', '')->set('area', (string) $area->id)->assertSee('Customer One')->assertDontSee('Supplier One')
            ->set('area', '')->set('search', '7654321')->assertSee('Supplier One')->assertDontSee('Customer One');
    });

    it('shows receivable and payable totals', function () {
        makeParty($this->owner, 'Customer', [PartyRole::Customer], '1500');
        makeParty($this->owner, 'Supplier', [PartyRole::Supplier], '400', SaveParty::WE_OWE_THEM);

        Livewire::test(PartyIndex::class)
            ->assertSee('1,500.00')
            ->assertSee('400.00')
            ->assertSee('Receivable')
            ->assertSee('Payable');
    });

    it('shows the statement with the current balance', function () {
        $customer = makeParty($this->owner, 'Statement customer', [PartyRole::Customer], '3200');

        Livewire::test(PartyStatement::class, ['party' => $customer])
            ->assertSee('Statement customer')
            ->assertSee('3,200.00')
            ->assertSee('Balance brought forward')
            ->set('from', now()->format('Y-m-d'))
            ->set('to', now()->subDay()->format('Y-m-d'))
            ->assertSee('Choose a valid date range');
    });
});
