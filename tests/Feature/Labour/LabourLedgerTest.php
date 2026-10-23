<?php

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerQueries;
use App\Accounting\LedgerVerifier;
use App\Accounting\PendingLine;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Labour\RecordWorkerMoney;
use App\Actions\Labour\SaveLabourCategory;
use App\Actions\Labour\SaveLabourRate;
use App\Actions\Production\ReverseProductionEntry;
use App\Actions\Settings\SetArchived;
use App\Enums\AccountType;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\JournalEntry;
use App\Models\LabourCategory;
use App\Models\LabourRate;
use App\Models\LedgerAccount;
use App\Models\ProductionEntry;
use App\Models\Settlement;
use App\Models\WorkEntry;
use App\Support\JournalPresenter;
use App\Support\Kiln\KilnDefaults;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000000');
});

/** Total of the labour expense accounts (6001–6499): the labour cost in the books. */
function labourCostInBooks(): string
{
    $ids = LedgerAccount::query()->where('type', AccountType::Expense)->whereBetween('code', ['6001', '6499'])->pluck('id')->all();

    return Money::sum(app(LedgerQueries::class)->balances($ids))->toDecimal();
}

describe('acceptance examples (plan M7, owner\'s rates)', function () {
    it('Pathai: 11,500 accepted × 1,200 / 1,000 = 13,800; advance 5,000; settlement pays 8,800; remaining 0', function () {
        $family = makeWorker($this->owner, 'Pathai family', 'pathai');
        setLabourRate($this->owner, 'pathai', '1200');

        $pathai = recordPathai($this->owner, '12000', '500', ['worker_id' => (string) $family->id]);
        expect($pathai->workEntry->amount->toDecimal())->toBe('13800.00')
            ->and($pathai->workEntry->quantity->format(0))->toBe('11,500');

        giveAdvance($this->owner, $family, $this->cash, '5000');
        expect(workerBalance($family))->toBe('8800.00');

        $settlement = settleWages($this->owner, $this->cash, [$family->id => '8800']);
        $line = $settlement->lines()->sole();

        expect($line->earned->toDecimal())->toBe('13800.00')
            ->and($line->advances->toDecimal())->toBe('5000.00')
            ->and($line->deductions->toDecimal())->toBe('0.00')
            ->and($line->payable->toDecimal())->toBe('8800.00')
            ->and($line->paid->toDecimal())->toBe('8800.00')
            ->and($line->remaining->toDecimal())->toBe('0.00')
            ->and($settlement->number)->toStartWith('ST-')
            ->and($settlement->entry->type)->toBe(JournalType::WagePayment)
            ->and(workerBalance($family))->toBe('0.00')
            ->and(balanceOf($this->cash))->toBe('986200.00')
            ->and(labourCostInBooks())->toBe('13800.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('Kamyaar: 11,000 × 410 / 1,000 = 4,510', function () {
        $kamyaar = makeWorker($this->owner, 'Kamyaar team', 'kamyaar');
        setLabourRate($this->owner, 'kamyaar', '410');
        recordPathai($this->owner, '11000');

        $loading = recordLoading($this->owner, '11000', ['worker_id' => (string) $kamyaar->id]);

        expect($loading->workEntry->amount->toDecimal())->toBe('4510.00')
            ->and(workerBalance($kamyaar))->toBe('4510.00');
    });

    it('Nakaasi: 11,000 × 325 / 1,000 = 3,575 (paid on the bricks unloaded, all grades)', function () {
        $nakaasi = makeWorker($this->owner, 'Nakaasi team', 'nakaasi');
        setLabourRate($this->owner, 'nakaasi', '325');
        recordPathai($this->owner, '11000');
        recordLoading($this->owner, '11000');
        recordFiring($this->owner, '11000');

        $entry = recordNakaasi($this->owner, ['awal' => '8000', 'doom' => '2000', 'khingar' => '700', 'tooty' => '300', 'rora' => '2.5'], ['worker_id' => (string) $nakaasi->id]);

        expect($entry->workEntry->amount->toDecimal())->toBe('3575.00')
            ->and($entry->workEntry->quantity->format(0))->toBe('11,000');
    });

    it('Mistri contract of 250,000 appears once in labour cost, never twice', function () {
        $mistri = makeWorker($this->owner, 'Firing mistri', 'mistri');

        $work = recordWork($this->owner, $mistri, '250000');
        settleWages($this->owner, $this->cash, [$mistri->id => '250000']);

        expect($work->amount->toDecimal())->toBe('250000.00')
            ->and($work->rate)->toBeNull()
            ->and(labourCostInBooks())->toBe('250000.00')            // the contract, once
            ->and((int) WorkEntry::query()->sum('amount'))->toBe(25000000) // one work entry, in paisa
            ->and(workerBalance($mistri))->toBe('0.00')
            ->and(balanceOf($this->cash))->toBe('750000.00');
    });
});

describe('rate cards', function () {
    it('uses the rate of the work day, and a worker\'s own rate over the category\'s', function () {
        $a = makeWorker($this->owner, 'Family A', 'pathai');
        $b = makeWorker($this->owner, 'Family B', 'pathai');
        setLabourRate($this->owner, 'pathai', '1000', now()->subDays(60)->format('Y-m-d'));
        setLabourRate($this->owner, 'pathai', '1200', now()->subDays(10)->format('Y-m-d'));
        setLabourRate($this->owner, 'pathai', '1300', now()->subDays(5)->format('Y-m-d'), $b);

        recordPathai($this->owner, '10000', '0', ['worker_id' => (string) $a->id, 'entry_date' => now()->subDays(20)->format('Y-m-d')]);
        recordPathai($this->owner, '10000', '0', ['worker_id' => (string) $a->id]);
        recordPathai($this->owner, '10000', '0', ['worker_id' => (string) $b->id, 'entry_date' => now()->subDays(8)->format('Y-m-d')]);
        recordPathai($this->owner, '10000', '0', ['worker_id' => (string) $b->id]);

        expect(WorkEntry::query()->where('worker_id', $a->id)->orderBy('work_date')->get()->map(fn ($w) => $w->amount->toDecimal())->all())->toBe(['10000.00', '12000.00'])
            ->and(WorkEntry::query()->where('worker_id', $b->id)->orderBy('work_date')->get()->map(fn ($w) => $w->amount->toDecimal())->all())->toBe(['12000.00', '13000.00']);
    });

    it('refuses work without a rate, a second rate on the same date, and a rate for a contract', function () {
        $a = makeWorker($this->owner, 'Family A', 'pathai');

        expect(fn () => recordPathai($this->owner, '1000', '0', ['worker_id' => (string) $a->id]))->toThrow(ValidationException::class, 'No rate is set');
        expect(ProductionEntry::query()->count())->toBe(0); // the bricks are not recorded without their wages

        setLabourRate($this->owner, 'pathai', '1200', '2026-01-01');
        expect(fn () => setLabourRate($this->owner, 'pathai', '1250', '2026-01-01'))->toThrow(ValidationException::class, 'A rate already starts on this date')
            ->and(fn () => setLabourRate($this->owner, 'mistri', '1000'))->toThrow(ValidationException::class, 'no rate');
    });

    it('removes an unused rate but keeps one that wages were worked out with', function () {
        $a = makeWorker($this->owner, 'Family A', 'pathai');
        $used = setLabourRate($this->owner, 'pathai', '1200');
        $unused = setLabourRate($this->owner, 'pathai', '1500', now()->addDays(7)->format('Y-m-d'));
        recordPathai($this->owner, '1000', '0', ['worker_id' => (string) $a->id]);

        app(SaveLabourRate::class)->remove($this->owner, $unused);

        expect(fn () => app(SaveLabourRate::class)->remove($this->owner, $used))->toThrow(ValidationException::class, 'stays')
            ->and($used->fresh())->not->toBeNull()
            ->and($unused->fresh())->toBeNull()
            ->and(DB::table('activity_log')->where('event', 'rate_removed')->count())->toBe(1)
            ->and(DB::table('activity_log')->where('event', 'rate_set')->count())->toBe(2);
    });

    it('keeps how a category is paid once wages were earned in it', function () {
        $a = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1500');
        $category = labourCategory('daily');
        $save = fn (string $basis) => app(SaveLabourCategory::class)->handle($this->owner, $category, ['name' => 'Daily labour', 'name_ur' => '', 'basis' => $basis, 'activity' => '', 'sort_order' => '60']);

        $save('per_month'); // no wages yet: allowed
        $save('per_day');
        recordWork($this->owner, $a, '2.5');

        expect(fn () => $save('per_month'))->toThrow(ValidationException::class, 'can no longer change')
            ->and(WorkEntry::query()->sole()->amount->toDecimal())->toBe('3750.00'); // 2.5 days × 1,500
    });

    it('names the labour account after its category and renames it with the category', function () {
        $a = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1500');
        recordWork($this->owner, $a, '1');

        $category = labourCategory('daily');
        app(SaveLabourCategory::class)->handle($this->owner, $category, ['name' => 'Helpers', 'name_ur' => 'مددگار', 'basis' => 'per_day', 'activity' => '', 'sort_order' => '60']);
        $account = LedgerAccount::query()->findOrFail($category->fresh()->ledger_account_id);

        expect($account->name)->toBe('Helpers wages')
            ->and($account->name_ur)->toBe('مددگار (مزدوری)')
            ->and((int) $account->code)->toBeGreaterThanOrEqual(6001)->toBeLessThanOrEqual(6499);
    });
});

describe('salaries and attendance', function () {
    beforeEach(function () {
        $this->month = now()->subMonthNoOverflow()->format('Y-m');
        $this->day = fn (int $d) => Carbon::createFromFormat('Y-m-d', $this->month.'-01')->addDays($d - 1)->format('Y-m-d');
    });

    it('pays the monthly salary less 1/30 per absent day and 1/60 per half day', function () {
        $barai = makeWorker($this->owner, 'Barai one', 'barai');
        $full = makeWorker($this->owner, 'Barai two', 'barai');
        setLabourRate($this->owner, 'barai', '30000', now()->subMonths(3)->format('Y-m-d'));

        markAttendance($this->owner, ($this->day)(3), [$barai->id => 'absent', $full->id => 'present']);
        markAttendance($this->owner, ($this->day)(4), [$barai->id => 'absent', $full->id => 'present']);
        markAttendance($this->owner, ($this->day)(10), [$barai->id => 'half', $full->id => 'present']);

        $posted = postSalaries($this->owner, $this->month);

        $byWorker = collect($posted)->keyBy('worker_id');
        expect($byWorker[$barai->id]->amount->toDecimal())->toBe('27500.00')   // 30,000 − 2 × 1,000 − 500
            ->and($byWorker[$barai->id]->quantity->toDecimal())->toBe('27.500') // paid days
            ->and($byWorker[$barai->id]->salary_month)->toBe($this->month)
            ->and($byWorker[$full->id]->amount->toDecimal())->toBe('30000.00')
            ->and($byWorker[$full->id]->work_date->format('Y-m-d'))->toBe(Carbon::createFromFormat('Y-m', $this->month)->endOfMonth()->format('Y-m-d'));
    });

    it('does not pay the days before joining, and uses the worker\'s own salary', function () {
        $new = makeWorker($this->owner, 'New Barai', 'barai', ['joined_on' => ($this->day)(11)]);
        setLabourRate($this->owner, 'barai', '30000', now()->subMonths(3)->format('Y-m-d'));
        setLabourRate($this->owner, 'barai', '36000', now()->subMonths(3)->format('Y-m-d'), $new);

        $posted = postSalaries($this->owner, $this->month);

        expect($posted[0]->amount->toDecimal())->toBe('24000.00'); // 36,000 less 10 days of 30
    });

    it('posts a salary once a month, locks that month\'s attendance, and both open again after a reversal', function () {
        $barai = makeWorker($this->owner, 'Barai one', 'barai');
        setLabourRate($this->owner, 'barai', '30000', now()->subMonths(3)->format('Y-m-d'));

        $first = postSalaries($this->owner, $this->month);
        expect($first)->toHaveCount(1)
            ->and(postSalaries($this->owner, $this->month))->toBe([])
            ->and(fn () => markAttendance($this->owner, ($this->day)(5), [$barai->id => 'absent']))->toThrow(ValidationException::class, 'already posted');

        app(ReverseEntry::class)->handle($this->owner, $first[0]->entry, ['reason' => 'Absences were missed'], (string) Str::uuid());
        markAttendance($this->owner, ($this->day)(5), [$barai->id => 'absent']);
        $again = postSalaries($this->owner, $this->month);

        expect($again[0]->amount->toDecimal())->toBe('29000.00')
            ->and(workerBalance($barai))->toBe('29000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('keeps attendance for salaried workers only, and refuses a month without a salary or not started', function () {
        $pathai = makeWorker($this->owner, 'Family A', 'pathai');
        makeWorker($this->owner, 'Barai one', 'barai');

        expect(fn () => markAttendance($this->owner, now()->format('Y-m-d'), [$pathai->id => 'absent']))->toThrow(ValidationException::class, 'salaried workers only')
            ->and(fn () => postSalaries($this->owner, $this->month))->toThrow(ValidationException::class, 'No salary is set')
            ->and(fn () => postSalaries($this->owner, now()->addMonthNoOverflow()->format('Y-m')))->toThrow(ValidationException::class, 'not started')
            ->and(Attendance::query()->count())->toBe(0);
    });

    it('updates a day\'s attendance instead of adding it twice', function () {
        $barai = makeWorker($this->owner, 'Barai one', 'barai');
        markAttendance($this->owner, now()->format('Y-m-d'), [$barai->id => 'absent']);
        markAttendance($this->owner, now()->format('Y-m-d'), [$barai->id => 'half']);

        expect(Attendance::query()->sole()->status->value)->toBe('half');
    });
});

describe('advances, deductions and settlements', function () {
    it('gives advances, takes them back, deducts, and settles what is left', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1500');

        recordWork($this->owner, $daily, '6');                                    // 9,000 earned
        giveAdvance($this->owner, $daily, $this->cash, '4000');
        giveAdvance($this->owner, $daily, $this->cash, '1000', direction: RecordWorkerMoney::RECEIVE); // 1,000 back
        deductFrom($this->owner, $daily, '500', 'Lost shovel');

        expect(workerBalance($daily))->toBe('5500.00');

        $settlement = settleWages($this->owner, $this->cash, [$daily->id => '5000']);
        $line = $settlement->lines()->sole();

        expect($line->advances->toDecimal())->toBe('3000.00')
            ->and($line->deductions->toDecimal())->toBe('500.00')
            ->and($line->payable->toDecimal())->toBe('5500.00')
            ->and($line->remaining->toDecimal())->toBe('500.00')
            ->and(workerBalance($daily))->toBe('500.00')
            ->and(balanceOf($this->cash))->toBe('992000.00') // −4,000 + 1,000 − 5,000
            ->and(app(LedgerQueries::class)->balance(LedgerAccount::bySystemKey(LedgerAccount::WORKER_DEDUCTIONS))->toDecimal())->toBe('500.00');
    });

    it('brings the balance forward into the next period', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');
        recordWork($this->owner, $daily, '3', now()->subDays(10)->format('Y-m-d'));
        recordWork($this->owner, $daily, '2');

        $line = settleWages($this->owner, $this->cash, [$daily->id => '5000'])->lines()->sole();

        expect($line->brought_forward->toDecimal())->toBe('3000.00')
            ->and($line->earned->toDecimal())->toBe('2000.00')
            ->and($line->payable->toDecimal())->toBe('5000.00');
    });

    it('refuses to pay more than is owed, a payment before the period ends, and more than the cash holds', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');
        recordWork($this->owner, $daily, '3');
        $empty = makeTreasuryAccount($this->owner, 'Small cash', '100');

        try {
            settleWages($this->owner, $this->cash, [$daily->id => '3000.01']);
            $this->fail('Overpayment was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('lines.0.paid');
        }

        expect(fn () => settleWages($this->owner, $this->cash, [$daily->id => '100'], to: now()->format('Y-m-d'), date: now()->subDay()->format('Y-m-d')))->toThrow(ValidationException::class)
            ->and(fn () => settleWages($this->owner, $empty, [$daily->id => '3000']))->toThrow(ValidationException::class)
            ->and(Settlement::query()->count())->toBe(0)
            ->and(workerBalance($daily))->toBe('3000.00');
    });

    it('keeps a settlement where nothing is paid, without a payment entry', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        giveAdvance($this->owner, $daily, $this->cash, '2000');

        $settlement = settleWages($this->owner, $this->cash, [$daily->id => '0']);

        expect($settlement->journal_entry_id)->toBeNull()
            ->and($settlement->lines()->sole()->remaining->toDecimal())->toBe('-2000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('returns the same settlement when the same request arrives twice', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');
        recordWork($this->owner, $daily, '3');

        $first = settleWages($this->owner, $this->cash, [$daily->id => '3000'], key: 'settle-1');
        $second = settleWages($this->owner, $this->cash, [$daily->id => '3000'], key: 'settle-1');

        expect($second->id)->toBe($first->id)
            ->and(Settlement::query()->count())->toBe(1)
            ->and(balanceOf($this->cash))->toBe('997000.00');
    });

    it('owes the workers again what a reversed settlement paid', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');
        recordWork($this->owner, $daily, '3');
        $settlement = settleWages($this->owner, $this->cash, [$daily->id => '3000']);

        app(ReverseEntry::class)->handle($this->owner, $settlement->entry, ['reason' => 'Paid in error'], (string) Str::uuid());

        expect($settlement->fresh()->isReversed())->toBeTrue()
            ->and(workerBalance($daily))->toBe('3000.00')
            ->and(balanceOf($this->cash))->toBe('1000000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('production wages', function () {
    beforeEach(function () {
        $this->family = makeWorker($this->owner, 'Pathai family', 'pathai');
        setLabourRate($this->owner, 'pathai', '1200');
    });

    it('cancels the wages with the production entry; a paid worker then owes them', function () {
        $pathai = recordPathai($this->owner, '10000', '0', ['worker_id' => (string) $this->family->id]);
        settleWages($this->owner, $this->cash, [$this->family->id => '12000']);

        expect(fn () => app(ReverseEntry::class)->handle($this->owner, $pathai->workEntry->entry, ['reason' => 'Wrong count'], (string) Str::uuid()))
            ->toThrow(ValidationException::class, 'Reverse that entry instead');

        app(ReverseProductionEntry::class)->handle($this->owner, $pathai, ['reason' => 'Wrong count']);

        expect($pathai->workEntry->entry->fresh()->isReversed())->toBeTrue()
            ->and(workerBalance($this->family))->toBe('-12000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses a worker who is not paid for that work, or is archived', function () {
        $nakaasi = makeWorker($this->owner, 'Nakaasi team', 'nakaasi');
        $gone = makeWorker($this->owner, 'Old family', 'pathai');
        app(SetArchived::class)->handle($this->owner, $gone, true, Permission::LabourWorkers);

        expect(fn () => recordPathai($this->owner, '1000', '0', ['worker_id' => (string) $nakaasi->id]))->toThrow(ValidationException::class, 'not paid for this work')
            ->and(fn () => recordPathai($this->owner, '1000', '0', ['worker_id' => (string) $gone->id]))->toThrow(ValidationException::class, 'archived')
            ->and(ProductionEntry::query()->count())->toBe(0);
    });

    it('pays nothing for production entries without a worker', function () {
        recordPathai($this->owner, '1000');

        expect(WorkEntry::query()->count())->toBe(0);
    });

    it('lets data entry name the worker on production, but not record labour', function () {
        $clerk = actingInKiln(makeUser($this->kiln, Role::DataEntry), $this->kiln);

        recordPathai($clerk, '1000', '0', ['worker_id' => (string) $this->family->id]);

        expect(workerBalance($this->family))->toBe('1200.00')
            ->and(fn () => giveAdvance($clerk, $this->family, $this->cash, '100'))->toThrow(AuthorizationException::class)
            ->and(fn () => recordWork($clerk, makeWorker($this->owner, 'Daily one', 'daily'), '1'))->toThrow(AuthorizationException::class);
    });
});

describe('ledger rules', function () {
    it('refuses wages payable lines without a worker, and workers on other accounts', function () {
        $worker = makeWorker($this->owner, 'Daily one', 'daily');
        $wages = LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE);
        $cash = $this->cash->ledgerAccount;
        $post = fn (array $lines) => app(JournalPoster::class)->post(JournalType::WorkerAdvance, now(), $lines, (string) Str::uuid(), $this->owner);

        expect(fn () => $post([PendingLine::debit($wages, Money::fromRupees('10')), PendingLine::credit($cash, Money::fromRupees('10'))]))->toThrow(LedgerIntegrityException::class, 'must name a worker')
            ->and(fn () => $post([PendingLine::debit($wages, Money::fromRupees('10'))->forWorker($worker), PendingLine::credit($cash, Money::fromRupees('10'))->forWorker($worker)]))->toThrow(LedgerIntegrityException::class, 'cannot name a worker');
    });

    it('refuses a worker of another kiln', function () {
        $other = makeSeededKiln();
        actingInKiln(makeUser($other, Role::Owner), $other);
        $stranger = makeWorker(auth()->user(), 'Elsewhere', 'daily');
        actingInKiln($this->owner, $this->kiln);

        $wages = LedgerAccount::bySystemKey(LedgerAccount::WAGES_PAYABLE);
        expect(fn () => app(JournalPoster::class)->post(JournalType::WorkerAdvance, now(), [
            PendingLine::debit($wages, Money::fromRupees('10'))->forWorker($stranger),
            PendingLine::credit($this->cash->ledgerAccount, Money::fromRupees('10')),
        ], (string) Str::uuid(), $this->owner))->toThrow(LedgerIntegrityException::class);
    });

    it('reports tampered wages lines, work entries and settlements', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');
        $work = recordWork($this->owner, $daily, '3');
        $settlement = settleWages($this->owner, $this->cash, [$daily->id => '3000']);
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        DB::table('work_entries')->where('id', $work->id)->update(['amount' => 1]);
        DB::table('settlement_lines')->where('settlement_id', $settlement->id)->update(['remaining' => 5]);
        DB::table('journal_lines')->where('journal_entry_id', $work->journal_entry_id)->whereNotNull('worker_id')->update(['worker_id' => null]);

        $problems = implode("\n", app(LedgerVerifier::class)->verify());
        expect($problems)->toContain('worker missing on a wages line')
            ->toContain("Work entry #{$work->id}")
            ->toContain("Settlement {$settlement->number}");
    });
});

describe('permissions and records', function () {
    it('lets only those allowed record labour', function () {
        $viewer = makeUser($this->kiln, Role::Viewer);
        $munshi = makeUser($this->kiln, Role::Munshi);
        $worker = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');

        expect(fn () => recordWork($viewer, $worker, '1'))->toThrow(AuthorizationException::class)
            ->and(fn () => makeWorker($viewer, 'Someone', 'daily'))->toThrow(AuthorizationException::class)
            ->and(fn () => setLabourRate($munshi, 'daily', '1100'))->toThrow(AuthorizationException::class);

        recordWork($munshi, $worker, '1');
        giveAdvance($munshi, $worker, $this->cash, '500');
        settleWages($munshi, $this->cash, [$worker->id => '500']);
        makeWorker($munshi, 'Another', 'daily');

        expect(workerBalance($worker))->toBe('0.00');
    });

    it('archives a worker only with a zero balance', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        giveAdvance($this->owner, $daily, $this->cash, '500');

        expect(fn () => app(SetArchived::class)->handle($this->owner, $daily, true, Permission::LabourWorkers))->toThrow(ValidationException::class, 'zero balance');

        giveAdvance($this->owner, $daily, $this->cash, '500', direction: RecordWorkerMoney::RECEIVE);
        app(SetArchived::class)->handle($this->owner, $daily, true, Permission::LabourWorkers);

        expect($daily->fresh()->is_active)->toBeFalse();
    });

    it('seeds the default categories once, without rates', function () {
        app(KilnDefaults::class)->seed($this->kiln);

        expect(LabourCategory::query()->orderBy('sort_order')->pluck('code')->all())->toBe(['pathai', 'kamyaar', 'nakaasi', 'barai', 'mistri', 'daily'])
            ->and(labourCategory('kamyaar')->activity?->value)->toBe('loading')
            ->and(labourCategory('barai')->basis->value)->toBe('per_month')
            ->and(LabourRate::query()->count())->toBe(0);
    });

    it('describes labour entries in words', function () {
        $daily = makeWorker($this->owner, 'Daily one', 'daily');
        setLabourRate($this->owner, 'daily', '1000');
        $work = recordWork($this->owner, $daily, '1');
        $advance = giveAdvance($this->owner, $daily, $this->cash, '100');

        expect(JournalPresenter::describe($work->entry))->toContain('Daily one')
            ->and(JournalPresenter::describe($advance))->toContain('Daily one')
            ->and(JournalEntry::query()->where('type', JournalType::Wages)->sole()->number)->toStartWith('WG-');
    });
});
