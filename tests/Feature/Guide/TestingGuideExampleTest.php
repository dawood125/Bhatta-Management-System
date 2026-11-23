<?php

use App\Actions\Parties\SaveParty;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Models\ExpenseCategory;
use App\Models\MaterialPurchaseCharge;
use App\Reports\CostPerThousand;
use App\Reports\FinancialStatements;

/*
| The worked example of docs/09-testing-guide.md ("A day at the test kiln"), entered through the
| same actions as the screens. Every number the guide tells the tester to expect is checked here,
| so the guide cannot drift from what the system really does.
*/

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

it('gives every number the testing guide tells the tester to expect', function () {
    $start = now()->subDay()->format('Y-m-d');
    $owner = $this->owner;

    // Part A: set-up.
    $cash = makeTreasuryAccount($owner, 'Cash', '500000', extra: ['opening_date' => $start]);
    $ali = makeParty($owner, 'Ali Builders', [PartyRole::Customer], '20000', SaveParty::THEY_OWE_US, ['opening_date' => $start]);
    $bilal = makeParty($owner, 'Bilal Traders', extra: ['opening_date' => $start]);
    $supplier = makeParty($owner, 'Coal Supplier', [PartyRole::Supplier], extra: ['opening_date' => $start]);
    $driver = makeParty($owner, 'Driver Karim', [PartyRole::Driver], extra: ['opening_date' => $start]);
    foreach (['pathai' => '1500', 'kamyaar' => '300', 'nakaasi' => '250', 'daily' => '1200'] as $code => $rate) {
        setLabourRate($owner, $code, $rate, $start);
    }
    $rashid = makeWorker($owner, 'Pathai team Rashid', 'pathai');
    $aslam = makeWorker($owner, 'Kamyaar Aslam', 'kamyaar');
    $javed = makeWorker($owner, 'Nakaasi team Javed', 'nakaasi');
    $naveed = makeWorker($owner, 'Daily worker Naveed', 'daily');

    // B1. Coal on credit, trolley paid in cash: landed cost 302,000 → 30,200 a ton.
    recordPurchase($owner, $supplier, [[material('coal'), '10', '30000']], [['transport', '2000', MaterialPurchaseCharge::FROM_ACCOUNT, $cash, 'Trolley']]);
    expect(stockOf(material('coal')))->toBe(['10.000', '302000.00'])
        ->and(partyBalance($supplier))->toBe('-300000.00')
        ->and(balanceOf($cash))->toBe('498000.00');

    // B2–B5. Production of the day, with the wages each team earns.
    recordPathai($owner, '10000', '200', ['worker_id' => (string) $rashid->id]);
    expect(workerBalance($rashid))->toBe('14700.00')->and(brickStock()['raw'])->toBe('9,800');

    recordLoading($owner, '9800', ['worker_id' => (string) $aslam->id]);
    expect(workerBalance($aslam))->toBe('2940.00')->and(brickStock()['in_firing'])->toBe('9,800');

    recordFiring($owner, '9800', [[material('coal'), '2']]);
    expect(brickStock()['awaiting'])->toBe('9,800')->and(stockOf(material('coal')))->toBe(['8.000', '241600.00']);

    recordNakaasi($owner, ['awal' => '8000', 'doom' => '1500', 'tooty' => '300'], ['worker_id' => (string) $javed->id]);
    expect(workerBalance($javed))->toBe('2450.00')
        ->and(brickStock()['saleable'])->toBe(['awal' => '8,000', 'doom' => '1,500', 'tooty' => '300']);

    // B6. A sale taken and partly paid at once, with carriage for the driver.
    $sale1 = recordSale($owner, $bilal, ['awal' => ['5000', '15000'], 'doom' => ['1000', '12000']], [
        'deliver_now' => true, 'driver_id' => (string) $driver->id, 'carriage' => '3000', 'received' => '50000', 'account_id' => (string) $cash->id,
    ]);
    expect($sale1->total->toDecimal())->toBe('87000.00')
        ->and($sale1->fresh()->status)->toBe(SaleStatus::Completed)
        ->and(partyBalance($bilal))->toBe('40000.00')
        ->and(salePayment($sale1->fresh())['status'])->toBe(PaymentStatus::PartlyPaid->value);

    // B7. A sale delivered later in two trips.
    $sale2 = recordSale($owner, $ali, ['awal' => ['2000', '15000']]);
    expect($sale2->status)->toBe(SaleStatus::Open);
    recordDispatch($owner, $sale2, ['awal' => '1200'], ['driver_id' => (string) $driver->id, 'carriage' => '2000']);
    expect($sale2->fresh()->status)->toBe(SaleStatus::Open);
    recordDispatch($owner, $sale2, ['awal' => '800'], ['driver_id' => (string) $driver->id, 'carriage' => '2000']);
    expect($sale2->fresh()->status)->toBe(SaleStatus::Completed)
        ->and(partyBalance($ali))->toBe('54000.00')
        ->and(brickStock()['saleable'])->toBe(['awal' => '1,000', 'doom' => '500', 'tooty' => '300']);

    // B8. A receipt pays the oldest first: the opening balance, then the sale.
    recordVoucher($owner, JournalType::Receipt, $cash, [[$ali, '30000']]);
    $paid = salePayment($sale2->fresh());
    expect(partyBalance($ali))->toBe('24000.00')
        ->and($paid['status'])->toBe(PaymentStatus::PartlyPaid->value)
        ->and($paid['paid'])->toBe('10000.00')
        ->and($paid['outstanding'])->toBe('24000.00');

    // B9. The driver is owed his three trips, and paid.
    expect(partyBalance($driver))->toBe('-7000.00');
    recordVoucher($owner, JournalType::Payment, $cash, [[$driver, '7000']]);
    expect(partyBalance($driver))->toBe('0.00');

    // B10–B12. Diesel, a day of daily labour, an advance.
    $fuel = ExpenseCategory::query()->where('name', 'Fuel and vehicles')->sole();
    recordExpense($owner, $cash, $fuel, '5000');
    recordWork($owner, $naveed, '1');
    giveAdvance($owner, $rashid, $cash, '5000');
    expect(workerBalance($naveed))->toBe('1200.00')->and(workerBalance($rashid))->toBe('9700.00');

    // B13. Everyone is paid what they are owed.
    $settlement = settleWages($owner, $cash, [$rashid->id => '9700', $aslam->id => '2940', $javed->id => '2450', $naveed->id => '1200']);
    expect($settlement->total_paid->toDecimal())->toBe('16290.00')
        ->and(array_map(fn ($worker) => workerBalance($worker), [$rashid, $aslam, $javed, $naveed]))->toBe(['0.00', '0.00', '0.00', '0.00']);

    // B14. Part of the coal bill paid.
    recordVoucher($owner, JournalType::Payment, $cash, [[$supplier, '100000']]);
    expect(partyBalance($supplier))->toBe('-200000.00')
        ->and(balanceOf($cash))->toBe('444710.00');

    // Part D: the reports of the day.
    $today = now()->startOfDay();
    $pnl = app(FinancialStatements::class)->profitAndLoss($today, $today);
    $groups = collect($pnl['groups'])->mapWithKeys(fn (array $group) => [$group['key'] => $group['total']->toDecimal()])->all();
    expect($pnl['total_income']->toDecimal())->toBe('117000.00')
        ->and($groups)->toBe(['materials' => '60400.00', 'labour' => '21290.00', 'expenses' => '5000.00'])
        ->and($pnl['net']->toDecimal())->toBe('30310.00')
        ->and(app(FinancialStatements::class)->balanceSheet($today)['balanced'])->toBeTrue();

    $cost = app(CostPerThousand::class)->forPeriod($today, $today);
    expect($cost['total_per_thousand']->toDecimal())->toBe('8845.92')
        ->and($cost['sales_per_thousand']->toDecimal())->toBe('14625.00')
        ->and($cost['margin_per_thousand']->toDecimal())->toBe('5779.08');
});
