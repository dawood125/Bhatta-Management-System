<?php

use App\Accounting\BooksLock;
use App\Accounting\LedgerVerifier;
use App\Actions\Accounting\CloseDay;
use App\Actions\Accounting\ReopenDay;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Sales\CloseSale;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\StageKind;
use App\Models\DayClose;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Sale;
use App\Models\User;
use App\Reports\CashDay;
use App\Reports\CostPerThousand;
use App\Reports\FinancialStatements;
use App\Reports\ProductionSummary;
use App\Reports\ReceivablesAgeing;
use App\Reports\SalesSummary;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * The known transactions every report is checked against (all today unless noted):
 * - cash opened 30 days ago with 1,000,000; an expense of 1,000 yesterday;
 * - 11,000 bricks made, loaded, fired and unloaded (Awal 8,000, Doom 2,000, Khingar 700, Tooty 300);
 * - coal bought on credit: 2 t @ 30,000 = 60,000; 1 t used = 30,000;
 * - a daily worker earns 4 × 1,500 = 6,000; a Pathai family gets an advance of 2,000;
 * - an expense of 10,000;
 * - 5,000 Awal sold @ 15,000 = 75,000, delivered, 50,000 received.
 */
beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);

    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000000');
    $this->customer = makeParty($this->owner, 'Demo Customer');
    $this->supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]);
    $category = ExpenseCategory::query()->orderBy('id')->firstOrFail();

    recordExpense($this->owner, $this->cash, $category, '1000', ['entry_date' => now()->subDay()->format('Y-m-d')]);
    makeSaleableStock($this->owner, ['awal' => '8000', 'doom' => '2000', 'khingar' => '700', 'tooty' => '300']);
    recordPurchase($this->owner, $this->supplier, [[material('coal'), '2', '30000']]);
    recordUsage($this->owner, [[material('coal'), '1']]);

    setLabourRate($this->owner, 'daily', '1500');
    recordWork($this->owner, makeWorker($this->owner, 'Daily one', 'daily'), '4');
    giveAdvance($this->owner, makeWorker($this->owner, 'Pathai family', 'pathai'), $this->cash, '2000');

    recordExpense($this->owner, $this->cash, $category, '10000');
    $this->sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
    recordDispatch($this->owner, $this->sale, ['awal' => '5000']);
    recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '50000']]);
});

describe('agreed acceptance examples', function () {
    it('totals the profit and loss of the known transactions', function () {
        $pnl = app(FinancialStatements::class)->profitAndLoss(now()->startOfDay(), now()->startOfDay());
        $groups = collect($pnl['groups'])->mapWithKeys(fn (array $group) => [$group['key'] => $group['total']->toDecimal()])->all();

        expect($pnl['total_income']->toDecimal())->toBe('75000.00')
            ->and($groups)->toBe(['materials' => '30000.00', 'labour' => '6000.00', 'expenses' => '10000.00'])
            ->and($pnl['total_expenses']->toDecimal())->toBe('46000.00')
            ->and($pnl['net']->toDecimal())->toBe('29000.00');
    });

    it('makes the balance sheet balance', function () {
        $sheet = app(FinancialStatements::class)->balanceSheet(now()->startOfDay());
        $lines = fn (array $side) => collect($side)->mapWithKeys(fn (array $row) => [$row['label'] => $row['amount']->toDecimal()])->all();

        expect($lines($sheet['assets']))->toBe([
            'Cash' => '1037000.00',
            'Material stock' => '30000.00',
            'Customers and others who owe us' => '25000.00',
            'Advances to workers' => '2000.00',
        ])
            ->and($lines($sheet['liabilities']))->toBe(['Suppliers and others we owe' => '60000.00', 'Wages due to workers' => '6000.00'])
            ->and($lines($sheet['equity']))->toBe(['Opening balances' => '1000000.00', 'Profit to date' => '28000.00'])
            ->and($sheet['total_assets']->toDecimal())->toBe('1094000.00')
            ->and($sheet['total_liabilities']->add($sheet['total_equity'])->toDecimal())->toBe('1094000.00')
            ->and($sheet['balanced'])->toBeTrue();
    });

    it('makes the net profit of a period equal the change in retained earnings', function () {
        $statements = app(FinancialStatements::class);
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

        expect($statements->retainedEarnings($yesterday)->toDecimal())->toBe('-1000.00')
            ->and($statements->retainedEarnings($today)->subtract($statements->retainedEarnings($yesterday))->toDecimal())
            ->toBe($statements->profitAndLoss($today, $today)['net']->toDecimal())
            ->and($statements->profitAndLoss($yesterday, $today)['net']->toDecimal())->toBe('28000.00')
            // Yesterday's balance sheet balances too (before most of the activity).
            ->and($statements->balanceSheet($yesterday)['balanced'])->toBeTrue();
    });
});

describe('other reports', function () {
    it('totals the production of the period, by stage and by grade', function () {
        $report = app(ProductionSummary::class)->forPeriod(now()->startOfDay(), now()->startOfDay());
        $awal = product('awal')->id;
        $saleableAwal = collect($report['slots'])->first(fn (array $slot) => $slot['stage'] === StageKind::Saleable && $slot['product_id'] === $awal);
        $raw = collect($report['slots'])->firstWhere('stage', StageKind::Raw);

        expect($report['pathai']['accepted']->format(0))->toBe('11,000')
            ->and($report['loaded']->format(0))->toBe('11,000')
            ->and($report['fired']->format(0))->toBe('11,000')
            ->and($report['unloaded']->format(0))->toBe('11,000')
            ->and($report['unloaded_by_product'][$awal]->format(0))->toBe('8,000')
            ->and($report['delivered_by_product'][$awal]->format(0))->toBe('5,000')
            ->and([$raw['opening']->format(0), $raw['in']->format(0), $raw['out']->format(0), $raw['closing']->format(0)])->toBe(['0', '11,000', '11,000', '0'])
            ->and([$saleableAwal['in']->format(0), $saleableAwal['out']->format(0), $saleableAwal['closing']->format(0)])->toBe(['8,000', '5,000', '3,000'])
            ->and($report['days'])->toHaveCount(1)
            ->and($report['days'][0]['delivered']->format(0))->toBe('5,000');
    });

    it('works out what 1,000 bricks cost and sold for', function () {
        $report = app(CostPerThousand::class)->forPeriod(now()->startOfDay(), now()->startOfDay());
        $per = collect($report['costs'])->mapWithKeys(fn (array $row) => [$row['key'] => $row['per_thousand']?->toDecimal()])->all();

        // 30,000 / 6,000 / 10,000 over 11,000 bricks out of the kiln; sales 75,000 over 5,000 bricks delivered.
        expect($per)->toBe(['materials' => '2727.27', 'labour' => '545.45', 'expenses' => '909.09'])
            ->and($report['total_per_thousand']->toDecimal())->toBe('4181.82')
            ->and($report['sales_per_thousand']->toDecimal())->toBe('15000.00')
            ->and($report['margin_per_thousand']->toDecimal())->toBe('10818.18')
            ->and(CostPerThousand::perThousand(Money::fromRupees('1000'), Quantity::zero()))->toBeNull();
    });

    it('summarises sales by grade, customer and agent', function () {
        $agent = makeParty($this->owner, 'Demo Agent', [PartyRole::Agent]);
        $second = makeParty($this->owner, 'Second Customer');
        $other = recordSale($this->owner, $second, ['doom' => ['1000', '12000', '500']], ['agent_id' => (string) $agent->id]);
        recordDispatch($this->owner, $other, ['doom' => '500']);
        app(CloseSale::class)->handle($this->owner, $other, ['reason' => 'Customer stopped']);

        $report = app(SalesSummary::class)->forPeriod(now()->startOfDay(), now()->startOfDay());
        $grades = collect($report['by_grade'])->mapWithKeys(fn (array $row) => [$row['product']->code => [$row['ordered']->format(0), $row['delivered']->format(0), $row['amount']->toDecimal()]])->all();

        expect($report['count'])->toBe(2)
            ->and($grades)->toBe(['awal' => ['5,000', '5,000', '75000.00'], 'doom' => ['1,000', '500', '11500.00']])
            // The rest of the Doom sale was closed: 11,500 − 5,750 = 5,750 billed.
            ->and($report['credits']->toDecimal())->toBe('5750.00')
            ->and($report['billed']->toDecimal())->toBe('80750.00')
            ->and($report['outstanding']->toDecimal())->toBe('30750.00')
            ->and(collect($report['by_customer'])->map(fn (array $row) => [$row['party']->name, $row['billed']->toDecimal()])->all())->toBe([['Demo Customer', '75000.00'], ['Second Customer', '5750.00']])
            ->and(collect($report['by_agent'])->map(fn (array $row) => [$row['agent']?->name, $row['count']])->all())->toBe([[null, 1], ['Demo Agent', 1]]);
    });

    it('ages what customers owe by the date of their unpaid sales', function () {
        $old = makeParty($this->owner, 'Old Customer', [PartyRole::Customer], '5000', extra: ['opening_date' => now()->subDays(100)->format('Y-m-d')]);
        recordSale($this->owner, $old, ['doom' => ['1000', '15000']], ['sale_date' => now()->subDays(45)->format('Y-m-d')]);
        recordSale($this->owner, $old, ['khingar' => ['600', '10000']]);

        $report = app(ReceivablesAgeing::class)->now();
        $row = collect($report['rows'])->first(fn (array $row) => $row['party']->is($old));

        expect($row['balance']->toDecimal())->toBe('26000.00')
            ->and(array_map(fn (Money $m) => $m->toDecimal(), $row['buckets']))->toBe(['0_30' => '6000.00', '31_60' => '15000.00', '61_90' => '0.00', 'over_90' => '0.00'])
            ->and($row['other']->toDecimal())->toBe('5000.00')
            ->and($report['totals']['balance']->toDecimal())->toBe('51000.00')
            ->and(collect($report['rows'])->first()['party']->is($old))->toBeTrue();
    });
});

describe('day close', function () {
    it('shows each cash account\'s day: opening + in − out = closing', function () {
        $day = collect(app(CashDay::class)->forDate(now()->startOfDay()))->firstWhere('account.id', $this->cash->id);

        // Yesterday's expense of 1,000 came before today; today 50,000 in, 10,000 + 2,000 out.
        expect([$day['opening']->toDecimal(), $day['in']->toDecimal(), $day['out']->toDecimal(), $day['closing']->toDecimal()])
            ->toBe(['999000.00', '50000.00', '12000.00', '1037000.00'])
            ->and($day['closing']->equals($this->cash->balance()))->toBeTrue();
    });

    it('closes the day with the cash counted, then keeps everyone but the owner out of it', function () {
        $munshi = makeUser($this->kiln, Role::Munshi);
        $category = ExpenseCategory::query()->orderBy('id')->firstOrFail();

        $close = app(CloseDay::class)->handle($munshi, [
            'close_date' => now()->format('Y-m-d'), 'notes' => 'Counted by the munshi', 'counts' => [$this->cash->id => '1,036,500'],
        ]);
        $count = $close->counts()->sole();

        expect($count->expected->toDecimal())->toBe('1037000.00')
            ->and($count->difference()->toDecimal())->toBe('-500.00')
            ->and(app(BooksLock::class)->closedThrough()->isToday())->toBeTrue();

        // Money, production, deliveries and reversals dated on the closed day are refused for everyone but the owner...
        $closedMessage = 'The books are closed up to '.now()->format('d-m-Y').'. Only the owner can add entries on or before that day.';
        expect(validationErrors(fn () => recordExpense($munshi, $this->cash, $category, '100'))['books_closed'])->toBe($closedMessage)
            ->and(validationErrors(fn () => recordPathai($munshi, '100'))['books_closed'])->toBe($closedMessage)
            ->and(validationErrors(fn () => recordDispatch($munshi, recordSaleBypassingLock($this->owner, $this->customer), ['doom' => '10']))['books_closed'])->toBe($closedMessage)
            ->and(validationErrors(fn () => app(ReverseEntry::class)->handle(makeUser($this->kiln, Role::Manager), JournalEntry::query()->where('type', JournalType::Receipt)->latest('id')->firstOrFail(), ['reason' => 'Entered twice'], (string) Str::uuid()))['books_closed'])->toBe($closedMessage)
            ->and(validationErrors(fn () => app(CloseDay::class)->handle($munshi, ['close_date' => now()->format('Y-m-d')]))['close_date'])->toBe('The books are already closed up to '.now()->format('d-m-Y').'.');

        // ...but the owner can still enter, and reopen the day.
        recordExpense($this->owner, $this->cash, $category, '100');
        expect(fn () => app(ReopenDay::class)->handle(makeUser($this->kiln, Role::Manager), $close, ['reason' => 'Late receipt']))->toThrow(AuthorizationException::class);

        app(ReopenDay::class)->handle($this->owner, $close, ['reason' => 'A late receipt came in']);
        recordExpense($munshi, $this->cash, $category, '100');

        expect(app(BooksLock::class)->closedThrough())->toBeNull()
            ->and($close->fresh()->isReopened())->toBeTrue()
            ->and(validationErrors(fn () => app(ReopenDay::class)->handle($this->owner, $close->fresh(), ['reason' => 'Again please']))['reason'])->toBe('Only the last closed day can be reopened.')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('closes an earlier day too, and keeps the history of closes', function () {
        app(CloseDay::class)->handle($this->owner, ['close_date' => now()->subDays(2)->format('Y-m-d')]);
        app(CloseDay::class)->handle($this->owner, ['close_date' => now()->subDay()->format('Y-m-d')]);

        expect(DayClose::query()->count())->toBe(2)
            ->and(app(BooksLock::class)->closedThrough()->isYesterday())->toBeTrue()
            ->and(app(BooksLock::class)->isClosed(now()))->toBeFalse()
            ->and(fn () => app(CloseDay::class)->handle(makeUser($this->kiln, Role::Viewer), ['close_date' => now()->format('Y-m-d')]))->toThrow(AuthorizationException::class)
            ->and(validationErrors(fn () => app(CloseDay::class)->handle($this->owner, ['close_date' => now()->addDay()->format('Y-m-d')])))->toHaveKey('close_date');
    });
});

/** A sale recorded by the owner (who may enter on a closed day), for testing deliveries. */
function recordSaleBypassingLock(User $owner, Party $customer): Sale
{
    return recordSale($owner, $customer, ['doom' => ['100', '12000']]);
}
