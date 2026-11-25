<?php

use App\Accounting\LedgerVerifier;
use App\Actions\Labour\SaveWorker;
use App\Actions\Materials\RecordMaterialPurchase;
use App\Actions\Production\RecordBrickAdjustment;
use App\Actions\Production\RecordPathai;
use App\Actions\Production\ReverseProductionEntry;
use App\Actions\Settings\SaveMaterial;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\StageKind;
use App\Labour\WorkerLedger;
use App\Livewire\Labour\WorkerIndex;
use App\Livewire\Production\EntryForms;
use App\Materials\MaterialStock;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Models\Worker;
use App\Reports\ProductionSummary;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Client changes of 2026-11-25: opening balances for workers, opening brick stock, Pathai made
 * by the machine (its operator paid by the day), and clearer labels for a material bought in
 * normal and big trolleys.
 */
beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

function saveWorkerWith(User $owner, ?Worker $worker, array $changes): Worker
{
    $base = $worker === null
        ? ['name' => 'Worker', 'name_ur' => '', 'phone' => '', 'labour_category_id' => (string) labourCategory('pathai')->id, 'joined_on' => '', 'notes' => '']
        : ['name' => $worker->name, 'name_ur' => '', 'phone' => '', 'labour_category_id' => (string) $worker->labour_category_id, 'joined_on' => '', 'notes' => ''];

    return app(SaveWorker::class)->handle($owner, $worker, array_merge($base, $changes));
}

function machinePathai(User $owner, Worker $operator, string $made, array $extra = []): ProductionEntry
{
    return recordPathai($owner, $made, '0', array_merge(['made_by' => RecordPathai::BY_MACHINE, 'worker_id' => (string) $operator->id], $extra));
}

describe('worker opening balance', function () {
    it('records wages already due as money the kiln owes the worker', function () {
        $worker = saveWorkerWith($this->owner, null, ['opening_amount' => '4000', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->subDays(3)->format('Y-m-d')]);

        $entry = JournalEntry::query()->findOrFail($worker->opening_entry_id);
        expect(workerBalance($worker))->toBe('4000.00')
            ->and($entry->type)->toBe(JournalType::OpeningBalance)
            ->and($worker->opening_balance->toDecimal())->toBe('4000.00')
            ->and(app(WorkerLedger::class)->totals()['owed_to_workers']->toDecimal())->toBe('4000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('records an advance already taken as money the worker owes', function () {
        $worker = saveWorkerWith($this->owner, null, ['opening_amount' => '5000', 'opening_direction' => SaveWorker::WORKER_OWES, 'opening_date' => now()->format('Y-m-d')]);

        expect(workerBalance($worker))->toBe('-5000.00')
            ->and(app(WorkerLedger::class)->totals()['advances_out']->toDecimal())->toBe('5000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('posts nothing for a zero opening balance', function () {
        $worker = makeWorker($this->owner, 'New worker', 'pathai');

        expect($worker->opening_entry_id)->toBeNull()
            ->and($worker->opening_date)->toBeNull()
            ->and(workerBalance($worker))->toBe('0.00');
    });

    it('reverses the old opening entry when it is changed, and keeps both in the history', function () {
        $worker = saveWorkerWith($this->owner, null, ['opening_amount' => '4000', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->format('Y-m-d')]);
        $first = $worker->opening_entry_id;

        $worker = saveWorkerWith($this->owner, $worker, ['opening_amount' => '2500', 'opening_direction' => SaveWorker::WORKER_OWES, 'opening_date' => now()->format('Y-m-d')]);

        expect(workerBalance($worker))->toBe('-2500.00')
            ->and(JournalEntry::query()->findOrFail($first)->isReversed())->toBeTrue()
            ->and($worker->opening_entry_id)->not->toBe($first);

        $worker = saveWorkerWith($this->owner, $worker, ['opening_amount' => '0', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->format('Y-m-d')]);

        expect(workerBalance($worker))->toBe('0.00')
            ->and($worker->opening_entry_id)->toBeNull()
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('leaves the opening entry alone when only the name changes', function () {
        $worker = saveWorkerWith($this->owner, null, ['opening_amount' => '4000', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->format('Y-m-d')]);
        $entryId = $worker->opening_entry_id;

        $worker = saveWorkerWith($this->owner, $worker, ['name' => 'Renamed', 'opening_amount' => '4000.00', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->format('Y-m-d')]);

        expect($worker->opening_entry_id)->toBe($entryId)
            ->and(JournalEntry::query()->count())->toBe(1);
    });

    it('needs a date for an opening balance', function () {
        expect(validationErrors(fn () => saveWorkerWith($this->owner, null, ['opening_amount' => '4000', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => ''])))
            ->toHaveKey('opening_date');
    });

    it('refuses an opening date after the worker\'s first transaction', function () {
        setLabourRate($this->owner, 'pathai', '1200');
        $worker = makeWorker($this->owner, 'Pathai family', 'pathai');
        recordWork($this->owner, $worker, '1000', now()->subDays(5)->format('Y-m-d'));

        expect(validationErrors(fn () => saveWorkerWith($this->owner, $worker, ['opening_amount' => '4000', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->format('Y-m-d')])))
            ->toHaveKey('opening_date');
    });

    it('counts an opening balance as brought forward in a settlement and pays it', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash box', '100000');
        setLabourRate($this->owner, 'pathai', '1200');
        $worker = saveWorkerWith($this->owner, null, ['opening_amount' => '4000', 'opening_direction' => SaveWorker::KILN_OWES, 'opening_date' => now()->subDays(3)->format('Y-m-d')]);
        recordWork($this->owner, $worker, '10000', now()->subDay()->format('Y-m-d'));

        $from = now()->subDays(6)->startOfDay();
        $summary = app(WorkerLedger::class)->periodSummary([$worker->id], $from, now()->startOfDay())[$worker->id];

        expect($summary['brought_forward']->toDecimal())->toBe('4000.00')
            ->and($summary['earned']->toDecimal())->toBe('12000.00')
            ->and($summary['advances']->toDecimal())->toBe('0.00')
            ->and($summary['payable']->toDecimal())->toBe('16000.00');

        settleWages($this->owner, $cash, [$worker->id => '16000']);

        expect(workerBalance($worker))->toBe('0.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('is entered on the Add worker form, and a double submit adds the worker once', function () {
        $component = Livewire::test(WorkerIndex::class)
            ->call('create')
            ->set('form.name', 'Old Pathai family')
            ->set('form.labour_category_id', (string) labourCategory('pathai')->id)
            ->set('form.opening_amount', '7500')
            ->set('form.opening_direction', SaveWorker::WORKER_OWES)
            ->call('save')
            ->assertHasNoErrors();

        // The same form key again (a second click before the dialog closed).
        $component->set('showForm', true)->call('save');

        $workers = Worker::query()->where('name', 'Old Pathai family')->get();
        expect($workers)->toHaveCount(1)
            ->and(workerBalance($workers->first()))->toBe('-7500.00');
    });

    it('shows the saved opening balance when the worker is edited', function () {
        $worker = saveWorkerWith($this->owner, null, ['opening_amount' => '5000', 'opening_direction' => SaveWorker::WORKER_OWES, 'opening_date' => '2026-11-01']);

        Livewire::test(WorkerIndex::class)
            ->call('edit', $worker->id)
            ->assertSet('form.opening_amount', '5000.00')
            ->assertSet('form.opening_direction', SaveWorker::WORKER_OWES)
            ->assertSet('form.opening_date', '2026-11-01');
    });
});

describe('opening brick stock', function () {
    it('adds bricks at any stage, marked as opening stock, with an optional reason', function () {
        $raw = recordBrickAdjustment($this->owner, StageKind::Raw, RecordBrickAdjustment::OPENING, '150000', null, '');
        $awal = recordBrickAdjustment($this->owner, StageKind::Saleable, RecordBrickAdjustment::OPENING, '80000', 'awal', '');
        recordBrickAdjustment($this->owner, StageKind::InFiring, RecordBrickAdjustment::OPENING, '200000', null, 'Round 3 in the kiln');

        $stock = brickStock();
        expect($stock['raw'])->toBe('150,000')
            ->and($stock['in_firing'])->toBe('200,000')
            ->and($stock['saleable']['awal'])->toBe('80,000')
            ->and($raw->is_opening)->toBeTrue()
            ->and($raw->notes)->toBe('Opening stock')
            ->and($awal->is_opening)->toBeTrue();
    });

    it('does not count opening stock as bricks made', function () {
        recordBrickAdjustment($this->owner, StageKind::Raw, RecordBrickAdjustment::OPENING, '150000', null, '');

        $summary = app(ProductionSummary::class)->forPeriod(now()->startOfMonth(), now()->endOfMonth());
        expect($summary['pathai']['made']->toDecimal())->toBe('0.000')
            ->and($summary['pathai']['accepted']->toDecimal())->toBe('0.000');
    });

    it('still needs a reason for an ordinary correction', function () {
        expect(validationErrors(fn () => recordBrickAdjustment($this->owner, StageKind::Raw, RecordBrickAdjustment::ADD, '100', null, '')))
            ->toHaveKey('reason');
    });

    it('shows the opening stock choice on the adjustment form', function () {
        Livewire::test(EntryForms::class)
            ->call('open', 'adjustment')
            ->assertSee('Opening stock (bricks already here when you started)')
            ->set('form.stage', StageKind::Raw->value)
            ->set('form.direction', RecordBrickAdjustment::OPENING)
            ->set('form.quantity', '5000')
            ->call('save')
            ->assertHasNoErrors();

        expect(brickStock()['raw'])->toBe('5,000');
    });
});

describe('Pathai made by the machine', function () {
    beforeEach(function () {
        setLabourRate($this->owner, 'machine_operator', '1500');
        setLabourRate($this->owner, 'pathai', '1200');
        $this->operator = makeWorker($this->owner, 'Hamza', 'machine_operator');
    });

    it('pays the operator one day, not per 1,000 bricks', function () {
        $entry = machinePathai($this->owner, $this->operator, '20000');

        expect($entry->by_machine)->toBeTrue()
            ->and($entry->worker_id)->toBe($this->operator->id)
            ->and(brickStock()['raw'])->toBe('20,000')
            ->and($entry->workEntry->amount->toDecimal())->toBe('1500.00')
            ->and($entry->workEntry->quantity->toDecimal())->toBe('1.000')
            ->and(workerBalance($this->operator))->toBe('1500.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('pays the day once, however many machine entries that day', function () {
        machinePathai($this->owner, $this->operator, '10000');
        $second = machinePathai($this->owner, $this->operator, '8000');
        machinePathai($this->owner, $this->operator, '9000', ['entry_date' => now()->subDay()->format('Y-m-d')]);

        expect($second->workEntry)->toBeNull()
            ->and(workerBalance($this->operator))->toBe('3000.00');
    });

    it('does not pay the day again when it was already entered in Work and wages', function () {
        recordWork($this->owner, $this->operator, '1');

        $entry = machinePathai($this->owner, $this->operator, '10000');

        expect($entry->workEntry)->toBeNull()
            ->and(workerBalance($this->operator))->toBe('1500.00');
    });

    it('needs the operator, and he must be paid by the day', function () {
        $family = makeWorker($this->owner, 'Pathai family', 'pathai');

        expect(validationErrors(fn () => recordPathai($this->owner, '1000', '0', ['made_by' => RecordPathai::BY_MACHINE, 'worker_id' => ''])))->toHaveKey('worker_id')
            ->and(validationErrors(fn () => machinePathai($this->owner, $family, '1000')))->toHaveKey('worker_id')
            ->and(brickStock()['raw'])->toBe('0');
    });

    it('still pays a hand team per 1,000 bricks', function () {
        $family = makeWorker($this->owner, 'Pathai family', 'pathai');

        $entry = recordPathai($this->owner, '10000', '0', ['made_by' => RecordPathai::BY_HAND, 'worker_id' => (string) $family->id]);

        expect($entry->by_machine)->toBeFalse()
            ->and(workerBalance($family))->toBe('12000.00');
    });

    it('moves the day to his other entry when the paid one is reversed', function () {
        $first = machinePathai($this->owner, $this->operator, '10000');
        $second = machinePathai($this->owner, $this->operator, '8000');

        app(ReverseProductionEntry::class)->handle($this->owner, $first, ['reason' => 'Entered twice']);

        expect(workerBalance($this->operator))->toBe('1500.00')
            ->and($second->fresh()->workEntry)->not->toBeNull();

        app(ReverseProductionEntry::class)->handle($this->owner, $second->fresh(), ['reason' => 'Entered twice']);

        expect(workerBalance($this->operator))->toBe('0.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('takes soil for machine bricks like hand bricks', function () {
        $supplier = makeParty($this->owner, 'Matti Supplier', [PartyRole::Supplier]);
        $soil = material('soil');
        app(SaveMaterial::class)->handle($this->owner, $soil, [
            'name' => $soil->name, 'name_ur' => $soil->name_ur, 'unit_id' => $soil->unit_id, 'kind' => $soil->kind->value,
            'sort_order' => $soil->sort_order, 'bricks_per_unit' => '2000', 'bricks_per_big_unit' => '2600',
        ]);
        recordPurchase($this->owner, $supplier, [[$soil->fresh(), '10', '3000']]);

        machinePathai($this->owner, $this->operator, '4000');

        expect(app(MaterialStock::class)->levels([$soil->id])[$soil->id]['quantity']->toDecimal())->toBe('8.000');
    });

    it('lists the operator in the Pathai form when Machine is chosen, and shows his day wage', function () {
        makeWorker($this->owner, 'Pathai family', 'pathai');

        Livewire::test(EntryForms::class)
            ->call('open', 'pathai')
            ->assertSee('Pathai family')
            ->assertDontSee('Hamza')
            ->set('form.made_by', RecordPathai::BY_MACHINE)
            ->assertSee('Hamza')
            ->assertDontSee('Pathai family')
            ->set('form.worker_id', (string) $this->operator->id)
            ->assertSee('1,500.00')
            ->assertSee('(1 day)')
            ->set('form.made', '20000')
            ->call('save')
            ->assertHasNoErrors();

        expect(workerBalance($this->operator))->toBe('1500.00');

        Livewire::test(EntryForms::class)
            ->call('open', 'pathai')
            ->set('form.made_by', RecordPathai::BY_MACHINE)
            ->set('form.worker_id', (string) $this->operator->id)
            ->assertSee('Already paid for this day');
    });

    it('clears the chosen worker when switching between hand and machine', function () {
        $family = makeWorker($this->owner, 'Pathai family', 'pathai');

        Livewire::test(EntryForms::class)
            ->call('open', 'pathai')
            ->set('form.worker_id', (string) $family->id)
            ->set('form.made_by', RecordPathai::BY_MACHINE)
            ->assertSet('form.worker_id', '');
    });
});

describe('normal and big trolley labels', function () {
    it('shows the cost per normal trolley on a purchase bought in big trolleys', function () {
        $supplier = makeParty($this->owner, 'Matti Supplier', [PartyRole::Supplier]);
        $soil = material('soil');
        app(SaveMaterial::class)->handle($this->owner, $soil, [
            'name' => $soil->name, 'name_ur' => $soil->name_ur, 'unit_id' => $soil->unit_id, 'kind' => $soil->kind->value,
            'sort_order' => $soil->sort_order, 'bricks_per_unit' => '2000', 'bricks_per_big_unit' => '2600',
        ]);

        // The partner's test: 2 big trolleys at Rs 4,000 = 2.6 normal trolleys for Rs 8,000.
        $purchase = app(RecordMaterialPurchase::class)->handle($this->owner, [
            'purchase_date' => now()->format('Y-m-d'), 'payment' => 'credit', 'supplier_id' => (string) $supplier->id,
            'account_id' => '', 'reference' => '', 'notes' => '', 'charges' => [],
            'lines' => [['material_id' => (string) $soil->id, 'quantity' => '2', 'rate' => '4000', 'size' => 'big']],
        ], (string) Str::uuid());

        $this->get(route('materials.purchases.show', $purchase))
            ->assertOk()
            ->assertSee('Rs 3,076.92 per normal trolley (2,000 bricks)')
            ->assertSee('Bought as 2.00 big trolleys');
    });
});
