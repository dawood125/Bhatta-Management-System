<?php

use App\Enums\Role;
use App\Livewire\Labour\AttendanceSheet;
use App\Livewire\Labour\RateIndex;
use App\Livewire\Labour\SettlementForm;
use App\Livewire\Labour\SettlementShow;
use App\Livewire\Labour\WorkerAccount;
use App\Livewire\Labour\WorkerIndex;
use App\Livewire\Labour\WorkIndex;
use App\Livewire\Production\EntryForms;
use App\Models\Attendance;
use App\Models\JournalEntry;
use App\Models\LabourCategory;
use App\Models\LabourRate;
use App\Models\Settlement;
use App\Models\WorkEntry;
use App\Models\Worker;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
});

describe('access', function () {
    it('opens the labour pages for those who may see them', function (Role $role, bool $sees, bool $pays) {
        $user = makeUser($this->kiln, $role);
        $worker = makeWorker($this->owner, 'Daily one', 'daily');

        foreach (['/labour', '/labour/work', '/labour/attendance', '/labour/settlements', '/labour/rates', "/labour/workers/{$worker->id}"] as $url) {
            $this->actingAs($user)->get($url)->assertStatus($sees ? 200 : 403);
        }
        $this->actingAs($user)->get('/labour/settlements/new')->assertStatus($pays ? 200 : 403);

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk();
        $sees ? $dashboard->assertSee(route('labour.index'), false) : $dashboard->assertDontSee(route('labour.index'), false);
    })->with([
        'owner' => [Role::Owner, true, true],
        'manager' => [Role::Manager, true, true],
        'munshi' => [Role::Munshi, true, true],
        'data entry' => [Role::DataEntry, false, false],
        'viewer' => [Role::Viewer, true, false],
    ]);

    it('shows no buttons to a viewer', function () {
        $worker = makeWorker($this->owner, 'Daily one', 'daily');
        $this->actingAs(makeUser($this->kiln, Role::Viewer));

        $this->get('/labour')->assertOk()->assertDontSee('Add worker');
        $this->get("/labour/workers/{$worker->id}")->assertOk()->assertDontSee('Give advance')->assertDontSee('Record work');
        $this->get('/labour/rates')->assertOk()->assertDontSee('Add category');
        $this->get('/labour/settlements')->assertOk()->assertDontSee('New settlement');
    });

    it('does not show another kiln\'s worker', function () {
        $other = makeSeededKiln();
        actingInKiln(makeUser($other, Role::Owner), $other);
        $stranger = makeWorker(auth()->user(), 'Elsewhere', 'daily');

        $this->actingAs($this->owner)->get("/labour/workers/{$stranger->id}")->assertNotFound();
    });
});

describe('workers', function () {
    it('adds, warns about a similar name, edits and lists workers with balances', function () {
        makeWorker($this->owner, 'Rafiq family', 'pathai');

        Livewire::test(WorkerIndex::class)
            ->call('create')
            ->set('form.name', 'Rafiq')
            ->assertSee('Similar names already exist')
            ->set('form.labour_category_id', (string) labourCategory('daily')->id)
            ->set('form.phone', '0300 1234567')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSee('Rafiq');

        $worker = Worker::query()->where('name', 'Rafiq')->sole();
        setLabourRate($this->owner, 'daily', '1000');
        recordWork($this->owner, $worker, '2');

        Livewire::test(WorkerIndex::class)
            ->assertSee('Wages due')
            ->assertSee('2,000.00')
            ->call('edit', $worker->id)
            ->set('form.name', 'Rafiq Ahmed')
            ->call('save')
            ->assertHasNoErrors()
            ->call('archive', $worker->id)
            ->assertDispatched('toast', type: 'error');

        expect($worker->fresh()->name)->toBe('Rafiq Ahmed')
            ->and($worker->fresh()->is_active)->toBeTrue();
    });

    it('shows a required-field error on the form', function () {
        Livewire::test(WorkerIndex::class)
            ->call('create')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.labour_category_id']);
    });
});

describe('worker account', function () {
    it('records work with the rate of the day, an advance and a deduction, then reverses one', function () {
        $worker = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1500');

        $page = Livewire::test(WorkerAccount::class, ['worker' => $worker])
            ->call('openWork')
            ->assertSee('1,500.00')
            ->set('form.quantity', '2.5')
            ->assertSee('3,750.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDialog', false)
            ->call('openMoney', 'give')
            ->set('form.amount', '1000')
            ->call('save')
            ->assertHasNoErrors()
            ->call('openDeduction')
            ->set('form.amount', '250')
            ->call('save')
            ->assertHasErrors(['form.memo'])
            ->set('form.memo', 'Broken shovel')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Broken shovel');

        expect(workerBalance($worker))->toBe('2500.00');

        $deduction = JournalEntry::query()->where('type', 'worker_deduction')->sole();
        $page->call('confirmReverse', $deduction->id)
            ->set('form.reason', 'Shovel was found')
            ->call('save')
            ->assertHasNoErrors();

        expect(workerBalance($worker))->toBe('2750.00');
    });

    it('tells when there is no rate, and shows salaried workers where their salary comes from', function () {
        $worker = makeWorker($this->owner, 'Daily one', 'daily');
        $barai = makeWorker($this->owner, 'Barai one', 'barai');

        Livewire::test(WorkerAccount::class, ['worker' => $worker])
            ->assertSee('No rate set yet')
            ->call('openWork')
            ->assertSee('No rate on that day')
            ->set('form.quantity', '1')
            ->call('save')
            ->assertHasErrors(['form.worker_id']);

        Livewire::test(WorkerAccount::class, ['worker' => $barai])
            ->assertDontSee('Record work')
            ->assertSee('Attendance and salaries');
    });

    it('lists production wages without a reverse button, and refuses an over-sized advance', function () {
        $family = makeWorker($this->owner, 'Pathai family', 'pathai');
        setLabourRate($this->owner, 'pathai', '1200');
        $pathai = recordPathai($this->owner, '1000', '0', ['worker_id' => (string) $family->id]);

        Livewire::test(WorkerAccount::class, ['worker' => $family])
            ->assertSee($pathai->workEntry->entry->number)
            ->assertSee($pathai->number)
            ->assertDontSee('Reverse '.$pathai->workEntry->entry->number)
            ->call('openMoney', 'give')
            ->set('form.amount', '100001')
            ->call('save')
            ->assertHasErrors(['form.amount']);
    });
});

describe('work and wages', function () {
    it('records work for any worker and totals the labour cost by category', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        $mistri = makeWorker($this->owner, 'Firing mistri', 'mistri');
        makeWorker($this->owner, 'Barai one', 'barai');
        setLabourRate($this->owner, 'daily', '1000');

        Livewire::test(WorkIndex::class)
            ->call('create')
            ->assertDontSee('Barai one')               // salaried: from attendance
            ->set('form.worker_id', (string) $daily->id)
            ->set('form.quantity', '3')
            ->assertSee('3,000.00')
            ->call('save', true)                        // and add another
            ->assertHasNoErrors()
            ->assertSet('showForm', true)
            ->set('form.worker_id', (string) $mistri->id)
            ->set('form.amount', '50000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSee('53,000.00')                    // labour cost
            ->assertSee('Mistri (firing team)');

        expect(WorkEntry::query()->count())->toBe(2);
    });
});

describe('attendance and salaries', function () {
    it('marks a day, posts the month\'s salaries and then locks that month', function () {
        $barai = makeWorker($this->owner, 'Barai one', 'barai');
        setLabourRate($this->owner, 'barai', '30000', now()->subMonths(3)->format('Y-m-d'), $barai);
        $lastMonth = now()->subMonthNoOverflow();

        $page = Livewire::test(AttendanceSheet::class)
            ->set('date', $lastMonth->copy()->startOfMonth()->addDays(4)->format('Y-m-d'))
            ->assertSet('month', $lastMonth->format('Y-m'))
            ->set("marks.{$barai->id}", 'absent')
            ->call('saveAttendance')
            ->assertHasNoErrors()
            ->assertSee('29,000.00')                     // salary preview
            ->call('postSalaries')
            ->assertHasNoErrors()
            ->assertSee('Posted');

        expect(Attendance::query()->sole()->status->value)->toBe('absent')
            ->and(workerBalance($barai))->toBe('29000.00');

        $page->set("marks.{$barai->id}", 'present')
            ->call('saveAttendance')
            ->assertHasErrors(['marks']);
    });

    it('explains the page when there are no salaried workers', function () {
        Livewire::test(AttendanceSheet::class)->assertSee('No salaried workers');
    });
});

describe('settlements', function () {
    it('starts each worker at what is owed, saves, and opens the payslips', function () {
        $a = makeWorker($this->owner, 'Family A', 'pathai');
        $b = makeWorker($this->owner, 'Family B', 'pathai');
        makeWorker($this->owner, 'Idle one', 'daily');
        setLabourRate($this->owner, 'pathai', '1200');
        recordPathai($this->owner, '11500', '0', ['worker_id' => (string) $a->id]);
        recordPathai($this->owner, '5000', '0', ['worker_id' => (string) $b->id]);
        giveAdvance($this->owner, $a, $this->cash, '5000');

        $form = Livewire::test(SettlementForm::class)
            ->set('category', (string) labourCategory('pathai')->id)
            ->assertSet("include.{$a->id}", true)
            ->assertSet("pay.{$a->id}", '8800.00')
            ->assertSet("pay.{$b->id}", '6000.00')
            ->assertSee('14,800.00')                   // total to pay
            ->set("pay.{$b->id}", '6000.01')
            ->call('save')
            ->assertHasErrors(["pay.{$b->id}"])
            ->set("pay.{$b->id}", '4000')
            ->call('save')
            ->assertHasNoErrors();

        $settlement = Settlement::query()->sole();
        $form->assertRedirect(route('labour.settlements.show', $settlement));

        expect($settlement->total_paid->toDecimal())->toBe('12800.00')
            ->and(workerBalance($a))->toBe('0.00')
            ->and(workerBalance($b))->toBe('2000.00');

        Livewire::test(SettlementShow::class, ['settlement' => $settlement])
            ->assertSee('Family A')
            ->assertSee('8,800.00')
            ->assertSee('Payslip')
            ->call('confirmReverse')
            ->set('reverseForm.reason', 'Paid twice by mistake')
            ->call('reverse')
            ->assertHasNoErrors();

        expect($settlement->fresh()->isReversed())->toBeTrue()
            ->and(workerBalance($a))->toBe('8800.00');
    });

    it('asks for a valid period and at least one worker', function () {
        Livewire::test(SettlementForm::class)
            ->set('periodTo', now()->format('Y-m-d'))
            ->set('settlementDate', now()->subDay()->format('Y-m-d'))
            ->assertSee('Choose a valid period')
            ->set('settlementDate', now()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors(['lines']);
    });
});

describe('categories and rates', function () {
    it('adds a category, sets a category rate and a worker\'s own rate, and removes an unused one', function () {
        Livewire::test(RateIndex::class)
            ->call('createCategory')
            ->set('categoryForm.name', 'Helpers')
            ->set('categoryForm.basis', 'per_day')
            ->call('saveCategory')
            ->assertHasNoErrors()
            ->assertSee('Helpers');

        $helpers = LabourCategory::query()->where('name', 'Helpers')->sole();
        $worker = makeWorker($this->owner, 'Helper one', 'daily', ['labour_category_id' => (string) $helpers->id]);

        $page = Livewire::test(RateIndex::class)
            ->call('createRate', $helpers->id)
            ->set('rateForm.rate', '900')
            ->call('saveRate')
            ->assertHasNoErrors()
            ->call('createRate', $helpers->id, true)
            ->set('rateForm.rate', '1100')
            ->call('saveRate')
            ->assertHasErrors(['rateForm.worker_id'])
            ->set('rateForm.worker_id', (string) $worker->id)
            ->call('saveRate')
            ->assertHasNoErrors()
            ->assertSee('900.00')
            ->assertSee('1,100.00')
            ->assertSee('In use');

        expect(LabourRate::query()->whereNull('worker_id')->sole()->rate->toDecimal())->toBe('900.00')
            ->and(LabourRate::query()->where('worker_id', $worker->id)->sole()->rate->toDecimal())->toBe('1100.00');

        $page->call('removeRate', LabourRate::query()->whereNull('worker_id')->sole()->id);
        expect(LabourRate::query()->whereNull('worker_id')->count())->toBe(0);
    });

    it('lets only those who set rates change them', function () {
        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);

        Livewire::test(RateIndex::class)
            ->assertDontSee('Add category')
            ->call('createCategory')
            ->assertForbidden();
    });
});

describe('production dialogs', function () {
    it('offers the workers paid for the work and shows their wages before saving', function () {
        $family = makeWorker($this->owner, 'Pathai family', 'pathai');
        makeWorker($this->owner, 'Nakaasi team', 'nakaasi');
        setLabourRate($this->owner, 'pathai', '1200');

        Livewire::test(EntryForms::class)
            ->call('open', 'pathai')
            ->assertSee('Pathai family')
            ->assertDontSee('Nakaasi team')
            ->set('form.worker_id', (string) $family->id)
            ->set('form.made', '12000')
            ->set('form.rejected', '500')
            ->assertSee('13,800.00')
            ->call('save')
            ->assertHasNoErrors();

        expect(workerBalance($family))->toBe('13800.00');
    });

    it('shows the error on the worker when no rate is set', function () {
        $team = makeWorker($this->owner, 'Kamyaar team', 'kamyaar');
        recordPathai($this->owner, '1000');

        Livewire::test(EntryForms::class)
            ->call('open', 'loading')
            ->set('form.worker_id', (string) $team->id)
            ->assertSee('No rate on that day')
            ->set('form.quantity', '1000')
            ->call('save')
            ->assertHasErrors(['form.worker_id']);
    });
});
