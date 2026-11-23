<?php

use App\Accounting\LedgerVerifier;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Labour\RecordWorkEntry;
use App\Actions\Materials\RecordMaterialPurchase;
use App\Actions\Materials\ReverseMaterialEntry;
use App\Actions\Production\ReverseProductionEntry;
use App\Actions\Settings\SaveMaterial;
use App\Actions\Trolleys\RecordTrolleyCost;
use App\Actions\Trolleys\SaveTrolley;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\StageKind;
use App\Livewire\Sales\DriverSettlement;
use App\Materials\MaterialStock;
use App\Models\Material;
use App\Models\ProductionEntry;
use App\Models\Trolley;
use App\Models\TrolleyCost;
use App\Models\User;
use App\Models\WorkEntry;
use App\Production\BrickStock;
use App\Reports\ProductionSummary;
use App\Sales\DriverSettlementSheet;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

function setYield(User $owner, Material $material, ?int $perUnit, ?int $perBig = null): Material
{
    return app(SaveMaterial::class)->handle($owner, $material, [
        'name' => $material->name,
        'name_ur' => $material->name_ur,
        'unit_id' => $material->unit_id,
        'kind' => $material->kind->value,
        'sort_order' => $material->sort_order,
        'bricks_per_unit' => $perUnit === null ? '' : (string) $perUnit,
        'bricks_per_big_unit' => $perBig === null ? '' : (string) $perBig,
    ])->load('unit');
}

function materialQty(Material $material): string
{
    return app(MaterialStock::class)->levels([$material->id])[$material->id]['quantity']->toDecimal();
}

function trolleyCost(User $actor, Trolley $trolley, string $kind, string $amount, array $extra = []): TrolleyCost
{
    return app(RecordTrolleyCost::class)->handle($actor, $extra + [
        'trolley_id' => (string) $trolley->id,
        'cost_date' => now()->format('Y-m-d'),
        'kind' => $kind,
        'amount' => $amount,
        'paid_from' => RecordTrolleyCost::FROM_ACCOUNT,
        'account_id' => '',
        'supplier_id' => '',
        'description' => '',
    ], (string) Str::uuid());
}

describe('soil and Kassu at Pathai', function () {
    beforeEach(function () {
        $this->supplier = makeParty($this->owner, 'Matti Supplier', [PartyRole::Supplier]);
        $this->soil = setYield($this->owner, material('soil'), 2000, 2600);
        $this->kassu = setYield($this->owner, material('kassu'), 17778);
    });

    it('counts a big trolley as 1.3 normal ones, priced per big trolley', function () {
        $purchase = app(RecordMaterialPurchase::class)->handle($this->owner, [
            'purchase_date' => now()->format('Y-m-d'), 'payment' => 'credit', 'supplier_id' => (string) $this->supplier->id,
            'account_id' => '', 'reference' => '', 'notes' => '', 'charges' => [],
            'lines' => [['material_id' => (string) $this->soil->id, 'quantity' => '10', 'rate' => '3000', 'size' => 'big']],
        ], (string) Str::uuid());

        expect($purchase->total->toDecimal())->toBe('30000.00')
            ->and(materialQty($this->soil))->toBe('13.000')
            ->and($purchase->lines->first()->big_quantity->toDecimal())->toBe('10.000')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses a big trolley for a material without that size', function () {
        expect(validationErrors(fn () => app(RecordMaterialPurchase::class)->handle($this->owner, [
            'purchase_date' => now()->format('Y-m-d'), 'payment' => 'credit', 'supplier_id' => (string) $this->supplier->id,
            'account_id' => '', 'reference' => '', 'notes' => '', 'charges' => [],
            'lines' => [['material_id' => (string) $this->kassu->id, 'quantity' => '1', 'rate' => '4000', 'size' => 'big']],
        ], (string) Str::uuid())))->toHaveKey('lines.0.size');
    });

    it('takes soil and Kassu for accepted bricks only, at average cost', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '3000'], [$this->kassu, '2', '4000']]);

        $entry = recordPathai($this->owner, '12000', '500');

        // 11,500 ÷ 2,000 and 11,500 ÷ 17,778
        expect(materialQty($this->soil))->toBe('4.250')
            ->and(materialQty($this->kassu))->toBe('1.353')
            ->and($entry->materialUsage->total_cost->toDecimal())->toBe('19838.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('matches the client figure for Kassu: about 22.5 trolleys per 4 lakh bricks', function () {
        expect($this->kassu->usedFor(Quantity::fromInput('400000', 0))->toDecimal())->toBe('22.500');
    });

    it('puts the materials back when the Pathai entry is reversed', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '3000'], [$this->kassu, '2', '4000']]);
        $entry = recordPathai($this->owner, '2000');

        app(ReverseProductionEntry::class)->handle($this->owner, $entry, ['reason' => 'Entered twice']);

        expect(materialQty($this->soil))->toBe('10.000')
            ->and(materialQty($this->kassu))->toBe('2.000')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('does not let the materials of a Pathai entry be reversed on their own', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '3000'], [$this->kassu, '2', '4000']]);
        $entry = recordPathai($this->owner, '2000');

        expect(validationErrors(fn () => app(ReverseMaterialEntry::class)->handle($this->owner, $entry->materialUsage->entry, ['reason' => 'Trying this'], (string) Str::uuid())))
            ->toHaveKey('reason');
    });

    it('refuses Pathai when there is not enough soil, and saves nothing', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '1', '3000'], [$this->kassu, '2', '4000']]);

        expect(validationErrors(fn () => recordPathai($this->owner, '2001')))->toHaveKey('made')
            ->and(ProductionEntry::query()->count())->toBe(0)
            ->and(materialQty($this->soil))->toBe('1.000');
    });

    it('lets a data entry user record Pathai with its materials', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '3000'], [$this->kassu, '2', '4000']]);
        $user = makeUser($this->kiln, Role::DataEntry);
        actingInKiln($user, $this->kiln);

        recordPathai($user, '2000');

        expect(materialQty($this->soil))->toBe('9.000');
    });

    it('takes nothing when a material has no bricks figure', function () {
        setYield($this->owner, $this->soil, null);
        setYield($this->owner, $this->kassu, null);

        expect(recordPathai($this->owner, '2000')->material_usage_id)->toBeNull();
    });

    it('refuses a big figure without a normal one on a raw material', function () {
        expect(validationErrors(fn () => setYield($this->owner, $this->soil, null, 2600)))->toHaveKey('bricks_per_unit');
    });
});

describe('re-firing', function () {
    beforeEach(function () {
        setLabourRate($this->owner, 'kamyaar', '400');
        setLabourRate($this->owner, 'nakaasi', '200');
        $this->kamyaar = makeWorker($this->owner, 'Kamyaar One', 'kamyaar');
        $this->nakaasi = makeWorker($this->owner, 'Nakaasi One', 'nakaasi');
        recordPathai($this->owner, '10000');
        recordLoading($this->owner, '10000', ['worker_id' => (string) $this->kamyaar->id]);
        recordFiring($this->owner, '10000');
    });

    it('pays each brick once and counts it once through a re-fire', function () {
        recordNakaasi($this->owner, ['awal' => '9500'], ['refire' => '500', 'worker_id' => (string) $this->nakaasi->id]);

        $stock = app(BrickStock::class);
        expect($stock->level(StageKind::AwaitingRefire)->toDecimal())->toBe('500.000')
            ->and($stock->level(StageKind::FiredAwaitingUnloading)->isZero())->toBeTrue();

        recordLoading($this->owner, '', ['refire' => '500', 'worker_id' => (string) $this->kamyaar->id]);
        expect($stock->level(StageKind::AwaitingRefire)->isZero())->toBeTrue()
            ->and($stock->level(StageKind::InFiring)->toDecimal())->toBe('500.000');

        recordFiring($this->owner, '500');
        recordNakaasi($this->owner, ['awal' => '500'], ['worker_id' => (string) $this->nakaasi->id]);

        $paid = fn (int $id) => WorkEntry::query()->where('worker_id', $id)->get()->sum(fn (WorkEntry $w) => $w->amount->paisa);
        $summary = app(ProductionSummary::class)->forPeriod(now()->startOfDay(), now()->startOfDay());

        expect($paid($this->kamyaar->id))->toBe(400000)      // 10,000 bricks at 400 per 1,000
            ->and($paid($this->nakaasi->id))->toBe(200000)   // 10,000 bricks at 200 per 1,000
            ->and($summary['unloaded']->toDecimal())->toBe('10000.000')
            ->and($summary['unloaded_by_product'])->toHaveCount(1)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('accepts a Nakaasi that only sends bricks back', function () {
        $entry = recordNakaasi($this->owner, [], ['refire' => '10000', 'worker_id' => (string) $this->nakaasi->id]);

        expect($entry->quantity->isZero())->toBeTrue()
            ->and(WorkEntry::query()->where('worker_id', $this->nakaasi->id)->count())->toBe(0);
    });

    it('never sends back more than are awaiting Nakaasi', function () {
        expect(validationErrors(fn () => recordNakaasi($this->owner, ['awal' => '9600'], ['refire' => '500'])))->toHaveKey('lines');
    });

    it('never loads more re-fire bricks than are waiting', function () {
        recordNakaasi($this->owner, ['awal' => '9500'], ['refire' => '500']);

        expect(validationErrors(fn () => recordLoading($this->owner, '', ['refire' => '501'])))->toHaveKey('refire');
    });

    it('still asks for bricks on an empty loading', function () {
        expect(validationErrors(fn () => recordLoading($this->owner, '')))->toHaveKey('quantity');
    });

    it('reverses a Nakaasi with re-fire, unless those bricks have moved on', function () {
        $entry = recordNakaasi($this->owner, ['awal' => '9500'], ['refire' => '500']);
        $load = recordLoading($this->owner, '', ['refire' => '500']);

        expect(validationErrors(fn () => app(ReverseProductionEntry::class)->handle($this->owner, $entry, ['reason' => 'Wrong count'])))->toHaveKey('reason');

        app(ReverseProductionEntry::class)->handle($this->owner, $load, ['reason' => 'Wrong count']);
        app(ReverseProductionEntry::class)->handle($this->owner, $entry, ['reason' => 'Wrong count']);

        expect(app(BrickStock::class)->level(StageKind::FiredAwaitingUnloading)->toDecimal())->toBe('10000.000')
            ->and(app(BrickStock::class)->level(StageKind::AwaitingRefire)->isZero())->toBeTrue()
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('machine operator', function () {
    it('is a daily-wage category earning Rs 1,500 for 8 days', function () {
        setLabourRate($this->owner, 'machine_operator', '1500');
        $worker = makeWorker($this->owner, 'Operator', 'machine_operator');

        $entry = app(RecordWorkEntry::class)->handle($this->owner, [
            'worker_id' => (string) $worker->id, 'work_date' => now()->format('Y-m-d'), 'quantity' => '8', 'amount' => '', 'description' => '',
        ], (string) Str::uuid());

        expect($entry->amount->toDecimal())->toBe('12000.00');
    });
});

describe('driver settlement', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash box', '100000');
        $this->customer = makeParty($this->owner, 'Customer One');
        $this->driver = makeParty($this->owner, 'Driver One', [PartyRole::Driver]);
        $this->trolley = app(SaveTrolley::class)->handle($this->owner, null, ['number' => 'TR-1', 'driver_id' => (string) $this->driver->id, 'is_active' => true]);
        makeSaleableStock($this->owner, ['awal' => '10000']);
        $this->sale = recordSale($this->owner, $this->customer, ['awal' => ['4000', '10000']]);
        foreach ([1, 2] as $trip) {
            recordDispatch($this->owner, $this->sale, ['awal' => '2000'], ['driver_id' => (string) $this->driver->id, 'carriage' => '3000']);
        }
        $this->row = fn (?CarbonInterface $month = null) => collect(app(DriverSettlementSheet::class)->forMonth($month ?? now()))
            ->firstWhere(fn (array $row) => $row['driver']->id === $this->driver->id);
    });

    it('takes diesel and maintenance out of the carriage and pays the rest', function () {
        trolleyCost($this->owner, $this->trolley, TrolleyCost::DIESEL, '1500', ['account_id' => (string) $this->cash->id]);
        trolleyCost($this->owner, $this->trolley, TrolleyCost::MAINTENANCE, '500', ['account_id' => (string) $this->cash->id]);

        $row = ($this->row)();
        expect($row['trips'])->toBe(2)
            ->and($row['carriage']->toDecimal())->toBe('6000.00')
            ->and($row['diesel']->toDecimal())->toBe('1500.00')
            ->and($row['maintenance']->toDecimal())->toBe('500.00')
            ->and($row['payable']->toDecimal())->toBe('4000.00')
            ->and(balanceOf($this->cash))->toBe('98000.00');

        recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->driver, '4000']]);

        $row = ($this->row)();
        expect($row['paid']->toDecimal())->toBe('4000.00')
            ->and($row['closing']->isZero())->toBeTrue()
            ->and($row['payable']->isZero())->toBeTrue()
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('carries a loss to the next month as what the driver owes', function () {
        trolleyCost($this->owner, $this->trolley, TrolleyCost::MAINTENANCE, '7000', ['account_id' => (string) $this->cash->id]);

        $row = ($this->row)();
        expect($row['closing']->toDecimal())->toBe('-1000.00')
            ->and($row['payable']->isZero())->toBeTrue();

        $next = ($this->row)(now()->addMonthNoOverflow());
        expect($next['brought_forward']->toDecimal())->toBe('-1000.00');
    });

    it('owes a pump on credit instead of taking cash', function () {
        $pump = makeParty($this->owner, 'Fuel Pump', [PartyRole::Supplier]);
        trolleyCost($this->owner, $this->trolley, TrolleyCost::DIESEL, '1000', ['paid_from' => RecordTrolleyCost::ON_CREDIT, 'supplier_id' => (string) $pump->id]);

        expect(balanceOf($this->cash))->toBe('100000.00')
            ->and(partyBalance($pump))->toBe('-1000.00')
            ->and(($this->row)()['payable']->toDecimal())->toBe('5000.00');
    });

    it('gives the cost back when its entry is reversed', function () {
        $cost = trolleyCost($this->owner, $this->trolley, TrolleyCost::DIESEL, '1500', ['account_id' => (string) $this->cash->id]);

        app(ReverseEntry::class)->handle($this->owner, $cost->entry, ['reason' => 'Wrong trolley'], (string) Str::uuid());

        $row = ($this->row)();
        expect($row['diesel']->isZero())->toBeTrue()
            ->and($row['payable']->toDecimal())->toBe('6000.00')
            ->and(balanceOf($this->cash))->toBe('100000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('sends the same form only once', function () {
        $input = ['trolley_id' => (string) $this->trolley->id, 'cost_date' => now()->format('Y-m-d'), 'kind' => 'diesel', 'amount' => '100', 'paid_from' => 'account', 'account_id' => (string) $this->cash->id, 'supplier_id' => '', 'description' => ''];
        $key = (string) Str::uuid();

        $first = app(RecordTrolleyCost::class)->handle($this->owner, $input, $key);
        $second = app(RecordTrolleyCost::class)->handle($this->owner, $input, $key);

        expect($second->id)->toBe($first->id)->and(TrolleyCost::query()->count())->toBe(1);
    });

    it('refuses more than the account holds', function () {
        expect(validationErrors(fn () => trolleyCost($this->owner, $this->trolley, TrolleyCost::DIESEL, '200000', ['account_id' => (string) $this->cash->id])))->toHaveKey('amount');
    });

    it('does not let a viewer add costs', function () {
        $viewer = makeUser($this->kiln, Role::Viewer);
        actingInKiln($viewer, $this->kiln);

        Livewire::test(DriverSettlement::class)->assertOk()->call('openCost')->assertForbidden();
    });

    it('records a cost and shows the settlement on the screen', function () {
        Livewire::test(DriverSettlement::class)
            ->assertSee('Driver One')
            ->assertSee('TR-1')
            ->call('openCost')
            ->set('costForm.trolley_id', (string) $this->trolley->id)
            ->set('costForm.amount', '1500')
            ->set('costForm.account_id', (string) $this->cash->id)
            ->call('saveCost')
            ->assertHasNoErrors()
            ->assertSee('4,500.00');
    });

    it('adds a trolley on the screen', function () {
        Livewire::test(DriverSettlement::class)
            ->call('openTrolley')
            ->set('trolleyForm.number', 'TR-2')
            ->set('trolleyForm.driver_id', (string) $this->driver->id)
            ->call('saveTrolley')
            ->assertHasNoErrors()
            ->assertSee('TR-2');
    });

    it('refuses a driver who is not a driver', function () {
        expect(validationErrors(fn () => app(SaveTrolley::class)->handle($this->owner, null, ['number' => 'TR-9', 'driver_id' => (string) $this->customer->id])))->toHaveKey('driver_id');
    });
});
