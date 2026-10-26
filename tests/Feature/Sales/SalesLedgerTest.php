<?php

use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerVerifier;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\CloseSale;
use App\Actions\Sales\ReceiveSalePayment;
use App\Actions\Sales\RecordSale;
use App\Actions\Sales\ReverseDispatch;
use App\Actions\Sales\WriteOffBalance;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Sales\SaleStock;
use App\Support\JournalPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000000');
    $this->customer = makeParty($this->owner, 'Demo Customer');
    $this->driver = makeParty($this->owner, 'Demo Driver', [PartyRole::Driver]);
    makeSaleableStock($this->owner, ['awal' => '8000', 'doom' => '2000', 'khingar' => '700', 'tooty' => '300']);
});

/** Free stock of a product as text (in stock now minus what open sales still have to collect). */
function freeStock(string $code): string
{
    $product = product($code);

    return app(SaleStock::class)->availability([$product])[$product->id]['free']->format(0);
}

describe('agreed acceptance example', function () {
    it('sells 5,000 Awal at 15,000 per 1,000 for 75,000; delivery takes Awal from 8,000 to 3,000; a receipt of 50,000 leaves 25,000 owed', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        expect($sale->total->toDecimal())->toBe('75000.00')
            ->and($sale->number)->toBe('INV-'.now()->year.'-0001')
            ->and($sale->entry->number)->toBe($sale->number)
            ->and($sale->status)->toBe(SaleStatus::Open)
            ->and(partyBalance($this->customer))->toBe('75000.00')
            // Reserved, not gone yet.
            ->and(brickStock()['saleable']['awal'])->toBe('8,000')
            ->and(freeStock('awal'))->toBe('3,000');

        recordDispatch($this->owner, $sale, ['awal' => '5000']);

        expect(brickStock()['saleable']['awal'])->toBe('3,000')
            ->and(freeStock('awal'))->toBe('3,000')
            ->and($sale->fresh()->status)->toBe(SaleStatus::Completed);

        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '50000']]);

        expect(partyBalance($this->customer))->toBe('25000.00')
            ->and(salePayment($sale))->toBe(['due' => '75000.00', 'paid' => '50000.00', 'outstanding' => '25000.00', 'status' => 'partly_paid'])
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses an order for more than the saleable stock', function () {
        $errors = validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['9000', '15000']]));

        expect($errors['lines.0.quantity'])->toBe('Only 8,000 free to sell (in stock 8,000, waiting for other sales 0).')
            ->and(Sale::query()->count())->toBe(0)
            ->and(JournalEntry::query()->where('type', JournalType::Sale)->count())->toBe(0);
    });
});

describe('stock', function () {
    it('keeps bricks of open sales reserved, so they are not sold twice', function () {
        recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        $errors = validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['3500', '15000']]));
        expect($errors['lines.0.quantity'])->toBe('Only 3,000 free to sell (in stock 8,000, waiting for other sales 5,000).');

        recordSale($this->owner, $this->customer, ['awal' => ['3000', '15000']]);
        expect(freeStock('awal'))->toBe('0');
    });

    it('delivers over several trips, never more than ordered', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000'], 'doom' => ['1000', '12000']]);

        $first = recordDispatch($this->owner, $sale, ['awal' => '2000']);
        expect($first->number)->toBe('DS-'.now()->year.'-0001')
            ->and($sale->fresh()->status)->toBe(SaleStatus::Open);

        expect(validationErrors(fn () => recordDispatch($this->owner, $sale, ['awal' => '3500']))['lines.0.quantity'])->toBe('Only 3,000 left to deliver.')
            ->and(validationErrors(fn () => recordDispatch($this->owner, $sale, []))['lines'])->toBe('Enter how much left with this delivery.');

        recordDispatch($this->owner, $sale, ['awal' => '3000', 'doom' => '1000']);
        expect($sale->fresh()->status)->toBe(SaleStatus::Completed)
            ->and(brickStock()['saleable'])->toMatchArray(['awal' => '3,000', 'doom' => '1,000'])
            ->and(validationErrors(fn () => recordDispatch($this->owner, $sale, ['awal' => '1']))['lines'])->toBe('Everything on this sale has been delivered or closed.');
    });

    it('refuses a back-dated delivery that would leave a day below zero, and one before its sale', function () {
        // The bricks were made today; a sale dated earlier can't have been delivered back then.
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']], ['sale_date' => now()->subDays(3)->format('Y-m-d')]);

        $date = now()->subDays(2);
        expect(validationErrors(fn () => recordDispatch($this->owner, $sale, ['awal' => '5000'], ['dispatch_date' => $date->format('Y-m-d')]))['lines.0.quantity'])
            ->toBe('Not enough Awal in stock on '.$date->format('d-m-Y').': this would leave -5,000.')
            ->and(validationErrors(fn () => recordDispatch($this->owner, $sale, ['awal' => '5000'], ['dispatch_date' => now()->subDays(4)->format('Y-m-d')]))['dispatch_date'])
            ->toBe('A delivery cannot be dated before its sale ('.now()->subDays(3)->format('d-m-Y').').');
    });

    it('sells Kachi from the raw stage once the kiln marks it saleable, and Rora by the trolley', function () {
        expect(validationErrors(fn () => recordSale($this->owner, $this->customer, ['kachi' => ['100', '8000']])))->toHaveKey('lines.0.product_id');

        Product::query()->where('code', 'kachi')->update(['is_saleable' => true]);
        recordPathai($this->owner, '2000');

        $kachi = recordSale($this->owner, $this->customer, ['kachi' => ['1500', '8000']]);
        recordDispatch($this->owner, $kachi, ['kachi' => '1500']);
        expect($kachi->total->toDecimal())->toBe('12000.00')
            ->and(brickStock()['raw'])->toBe('500');

        recordPathai($this->owner, '1000');
        recordLoading($this->owner, '1000');
        recordFiring($this->owner, '1000');
        recordNakaasi($this->owner, ['awal' => '1000', 'rora' => '3']);

        $rora = recordSale($this->owner, $this->customer, ['rora' => ['2.5', '3000']]);
        expect($rora->total->toDecimal())->toBe('7500.00')
            ->and($rora->lines->first()->rate_per)->toBe(1);
    });
});

describe('money', function () {
    it('charges carriage to the customer and owes it to the driver, sharing the DS numbers', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $trip = recordDispatch($this->owner, $sale, ['awal' => '5000'], ['driver_id' => (string) $this->driver->id, 'vehicle' => 'LHR-1234', 'carriage' => '6000']);

        expect($trip->carriageEntry->type)->toBe(JournalType::Carriage)
            ->and($trip->carriageEntry->number)->toBe($trip->number)
            ->and($trip->number)->toBe('DS-'.now()->year.'-0001')
            ->and(partyBalance($this->customer))->toBe('81000.00')
            ->and(partyBalance($this->driver))->toBe('-6000.00')
            ->and(salePayment($sale)['due'])->toBe('81000.00');

        $other = recordSale($this->owner, $this->customer, ['doom' => ['1000', '12000']]);
        expect(recordDispatch($this->owner, $other, ['doom' => '1000'])->number)->toBe('DS-'.now()->year.'-0002');
    });

    it('refuses carriage without a driver, a driver who is the customer, and a party who is not a driver', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]);
        $both = makeParty($this->owner, 'Customer Driver', [PartyRole::Customer, PartyRole::Driver]);
        $own = recordSale($this->owner, $both, ['doom' => ['100', '12000']]);

        expect(validationErrors(fn () => recordDispatch($this->owner, $sale, ['awal' => '100'], ['carriage' => '500']))['driver_id'])->toBe('Choose the driver who is owed the carriage.')
            ->and(validationErrors(fn () => recordDispatch($this->owner, $sale, ['awal' => '100'], ['driver_id' => (string) $supplier->id]))['driver_id'])->toBe('Choose an active party with the driver role.')
            ->and(validationErrors(fn () => recordDispatch($this->owner, $own, ['doom' => '100'], ['driver_id' => (string) $both->id]))['driver_id'])->toBe('The driver cannot be the customer of this sale.');
    });

    it('records a sale delivered and paid on the spot', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']], [
            'deliver_now' => true, 'driver_id' => (string) $this->driver->id, 'carriage' => '6000',
            'received' => '81000', 'account_id' => (string) $this->cash->id,
        ]);

        $receipt = $sale->receiptEntry;
        expect($sale->status)->toBe(SaleStatus::Completed)
            ->and($sale->dispatches)->toHaveCount(1)
            ->and(brickStock()['saleable']['awal'])->toBe('3,000')
            ->and($receipt->type)->toBe(JournalType::Receipt)
            ->and($receipt->lines->first()->ledger_account_id)->toBe($this->cash->ledger_account_id)
            ->and(partyBalance($this->customer))->toBe('0.00')
            ->and(partyBalance($this->driver))->toBe('-6000.00')
            ->and(balanceOf($this->cash))->toBe('1081000.00')
            ->and(salePayment($sale)['status'])->toBe('paid')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);

        expect(validationErrors(fn () => recordSale($this->owner, $this->customer, ['doom' => ['100', '12000']], ['received' => '1000']))['account_id'])
            ->toBe('Choose the account the money went into.');
    });

    it('works out what each sale has been paid, oldest first, counting the opening balance and advances', function () {
        $customer = makeParty($this->owner, 'Old Customer', [PartyRole::Customer], '5000');
        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$customer, '20000']]);

        $first = recordSale($this->owner, $customer, ['awal' => ['2000', '15000']]);
        $second = recordSale($this->owner, $customer, ['doom' => ['1000', '15000']]);

        // 20,000 received: 5,000 settles the opening balance, 15,000 goes to the first sale.
        expect(salePayment($first))->toBe(['due' => '30000.00', 'paid' => '15000.00', 'outstanding' => '15000.00', 'status' => 'partly_paid'])
            ->and(salePayment($second)['status'])->toBe('unpaid');

        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$customer, '35000']]);
        expect(salePayment($first)['status'])->toBe('paid')
            ->and(salePayment($second)['status'])->toBe('paid')
            ->and(partyBalance($customer))->toBe('-5000.00');
    });
});

describe('money received for a sale', function () {
    it('lets money received on a sale\'s page pay that sale first, and anything more the oldest sales', function () {
        $first = recordSale($this->owner, $this->customer, ['awal' => ['2000', '15000']]);
        $second = recordSale($this->owner, $this->customer, ['doom' => ['1000', '15000']]);
        $input = ['entry_date' => now()->format('Y-m-d'), 'amount' => '20000', 'account_id' => (string) $this->cash->id, 'memo' => ''];

        $receipt = app(ReceiveSalePayment::class)->handle($this->owner, $second, $input, (string) Str::uuid());

        // 15,000 pays the second sale it was taken for; the other 5,000 goes to the oldest sale.
        expect($receipt->type)->toBe(JournalType::Receipt)
            ->and($second->receipts()->sole()->journal_entry_id)->toBe($receipt->id)
            ->and(salePayment($second)['status'])->toBe('paid')
            ->and(salePayment($first))->toBe(['due' => '30000.00', 'paid' => '5000.00', 'outstanding' => '25000.00', 'status' => 'partly_paid'])
            ->and(partyBalance($this->customer))->toBe('25000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([])
            ->and(validationErrors(fn () => app(ReceiveSalePayment::class)->handle($this->owner, $first, ['amount' => ''] + $input, (string) Str::uuid())))->toHaveKey('amount')
            ->and(fn () => app(ReceiveSalePayment::class)->handle(makeUser($this->kiln, Role::Viewer), $first, $input, (string) Str::uuid()))->toThrow(AuthorizationException::class);

        // A reversed receipt no longer pays anything.
        app(ReverseEntry::class)->handle($this->owner, $receipt, ['reason' => 'Cheque bounced'], (string) Str::uuid());
        expect(salePayment($second)['status'])->toBe('unpaid');
    });

    it('gives money received for a cancelled sale to the customer\'s other sales', function () {
        $first = recordSale($this->owner, $this->customer, ['awal' => ['2000', '15000']]);
        $second = recordSale($this->owner, $this->customer, ['doom' => ['1000', '15000']], ['received' => '15000', 'account_id' => (string) $this->cash->id]);
        expect(salePayment($second)['status'])->toBe('paid');

        app(CancelSale::class)->handle($this->owner, $second, ['reason' => 'Customer took Awal instead']);
        expect(salePayment($first)['paid'])->toBe('15000.00')
            ->and(partyBalance($this->customer))->toBe('15000.00');
    });
});

describe('corrections', function () {
    it('cancels a sale while nothing was delivered, and refuses once bricks left', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        app(CancelSale::class)->handle($this->owner, $sale, ['reason' => 'Customer changed his mind']);

        expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled)
            ->and($sale->entry->fresh()->isReversed())->toBeTrue()
            ->and(freeStock('awal'))->toBe('8,000')
            ->and(partyBalance($this->customer))->toBe('0.00')
            ->and(validationErrors(fn () => app(CancelSale::class)->handle($this->owner, $sale->fresh(), ['reason' => 'Again please']))['reason'])->toBe('This sale has already been cancelled.');

        $delivered = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $trip = recordDispatch($this->owner, $delivered, ['awal' => '1000']);
        expect(validationErrors(fn () => app(CancelSale::class)->handle($this->owner, $delivered, ['reason' => 'Wrong customer']))['reason'])
            ->toBe('Bricks of this sale were delivered. Reverse those deliveries first, or close what is left.');

        app(ReverseDispatch::class)->handle($this->owner, $trip, ['reason' => 'Entered by mistake']);
        app(CancelSale::class)->handle($this->owner, $delivered, ['reason' => 'Wrong customer']);
        expect(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('closes the rest of a partly delivered sale with a credit note, sharing the discount', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000', '1000']]);
        expect($sale->total->toDecimal())->toBe('74000.00');

        expect(validationErrors(fn () => app(CloseSale::class)->handle($this->owner, $sale, ['reason' => 'Customer stopped']))['reason'])
            ->toBe('Nothing was delivered yet: cancel the sale instead.');

        $trip = recordDispatch($this->owner, $sale, ['awal' => '2000']);
        app(CloseSale::class)->handle($this->owner, $sale, ['reason' => 'Customer stopped']);

        $sale->refresh();
        // Delivered share: 74,000 × 2,000 / 5,000 = 29,600; the credit note is the other 44,400.
        expect($sale->creditEntry->total->toDecimal())->toBe('44400.00')
            ->and($sale->creditEntry->type)->toBe(JournalType::SaleCredit)
            ->and($sale->status)->toBe(SaleStatus::Completed)
            ->and(partyBalance($this->customer))->toBe('29600.00')
            ->and(salePayment($sale)['due'])->toBe('29600.00')
            ->and(freeStock('awal'))->toBe('6,000')
            ->and(validationErrors(fn () => app(ReverseDispatch::class)->handle($this->owner, $trip, ['reason' => 'Returned bricks']))['reason'])
            ->toBe('This sale was closed, so its deliveries can no longer be reversed.')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('reverses a delivery: the bricks come back, the carriage is cancelled and they are to deliver again', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $trip = recordDispatch($this->owner, $sale, ['awal' => '5000'], ['driver_id' => (string) $this->driver->id, 'carriage' => '6000']);

        app(ReverseDispatch::class)->handle($this->owner, $trip, ['reason' => 'Truck came back']);

        expect($trip->fresh()->isReversed())->toBeTrue()
            ->and(brickStock()['saleable']['awal'])->toBe('8,000')
            ->and(freeStock('awal'))->toBe('3,000')
            ->and($sale->fresh()->status)->toBe(SaleStatus::Open)
            ->and(partyBalance($this->driver))->toBe('0.00')
            ->and(partyBalance($this->customer))->toBe('75000.00')
            ->and(validationErrors(fn () => app(ReverseDispatch::class)->handle($this->owner, $trip->fresh(), ['reason' => 'Truck came back']))['reason'])->toBe('This delivery has already been reversed.')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('writes off what a customer cannot pay, by the owner only, and reverses it from the transactions screen', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $manager = makeUser($this->kiln, Role::Manager);
        $input = ['entry_date' => now()->format('Y-m-d'), 'amount' => '75000', 'reason' => 'Customer left the area'];

        expect(fn () => app(WriteOffBalance::class)->handle($manager, $this->customer, $input, (string) Str::uuid()))->toThrow(AuthorizationException::class)
            ->and(validationErrors(fn () => app(WriteOffBalance::class)->handle($this->owner, $this->customer, ['amount' => '80000'] + $input, (string) Str::uuid()))['amount'])
            ->toBe('At most what the customer owes on that day (Rs 75,000.00).');

        $entry = app(WriteOffBalance::class)->handle($this->owner, $this->customer, $input, (string) Str::uuid());
        expect($entry->type)->toBe(JournalType::BadDebt)
            ->and($entry->lines()->with('account')->first()->account->system_key)->toBe(LedgerAccount::BAD_DEBTS)
            ->and(partyBalance($this->customer))->toBe('0.00')
            ->and(salePayment($sale)['status'])->toBe('paid')
            ->and(validationErrors(fn () => app(WriteOffBalance::class)->handle($this->owner, $this->customer, $input, (string) Str::uuid()))['amount'])->toBe('This customer owes nothing on that day.');

        app(ReverseEntry::class)->handle($this->owner, $entry, ['reason' => 'Customer paid after all'], (string) Str::uuid());
        expect(partyBalance($this->customer))->toBe('75000.00');
    });
});

describe('rules', function () {
    it('refuses a discount above the line amount, a duplicate grade, a zero total and a date before the customer opened', function () {
        expect(validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['100', '15000', '2000']]))['lines.0.discount'])
            ->toBe('The discount cannot be more than the amount (Rs 1,500.00).')
            ->and(validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['100', '15000', '1500']]))['lines'])
            ->toBe('The total of the sale must be more than zero.')
            ->and(validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['100', '15000']], ['sale_date' => now()->subDays(40)->format('Y-m-d')]))['sale_date'])
            ->toContain('before the opening date of Demo Customer')
            ->and(validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['100', '15000']], ['sale_date' => now()->addDay()->format('Y-m-d')])))->toHaveKey('sale_date');

        $awal = (string) product('awal')->id;
        $errors = validationErrors(fn () => app(RecordSale::class)->handle($this->owner, [
            'sale_date' => now()->format('Y-m-d'), 'customer_id' => (string) $this->customer->id,
            'lines' => [['product_id' => $awal, 'quantity' => '1', 'rate' => '1'], ['product_id' => $awal, 'quantity' => '1', 'rate' => '1']],
        ], (string) Str::uuid()));
        expect($errors['lines.1.product_id'])->toBe('This grade is already on the sale.');
    });

    it('needs a customer, and an agent with the sales agent role', function () {
        $supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]);

        expect(validationErrors(fn () => recordSale($this->owner, $supplier, ['awal' => ['100', '15000']]))['customer_id'])->toBe('Choose an active party with the customer role.')
            ->and(validationErrors(fn () => recordSale($this->owner, $this->customer, ['awal' => ['100', '15000']], ['agent_id' => (string) $supplier->id]))['agent_id'])->toBe('Choose an active party with the sales agent role.');

        $agent = makeParty($this->owner, 'Demo Agent', [PartyRole::Agent]);
        expect(recordSale($this->owner, $this->customer, ['awal' => ['100', '15000']], ['agent_id' => (string) $agent->id])->agent_id)->toBe($agent->id);
    });

    it('returns the same sale and delivery when the same form is sent twice', function () {
        $key = (string) Str::uuid();
        $first = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']], [], $key);
        $again = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']], [], $key);

        $tripKey = (string) Str::uuid();
        $trip = recordDispatch($this->owner, $first, ['awal' => '1000'], [], $tripKey);

        expect($again->id)->toBe($first->id)
            ->and(Sale::query()->count())->toBe(1)
            ->and(recordDispatch($this->owner, $first, ['awal' => '1000'], [], $tripKey)->id)->toBe($trip->id)
            ->and(SaleDispatch::query()->count())->toBe(1);
    });

    it('lets the munshi record sales, only the owner and manager correct them, and viewers and data entry nothing', function () {
        $munshi = makeUser($this->kiln, Role::Munshi);
        $viewer = makeUser($this->kiln, Role::Viewer);
        $dataEntry = makeUser($this->kiln, Role::DataEntry);

        $sale = recordSale($munshi, $this->customer, ['awal' => ['100', '15000']]);
        recordDispatch($munshi, $sale, ['awal' => '50']);

        expect(fn () => recordSale($viewer, $this->customer, ['awal' => ['100', '15000']]))->toThrow(AuthorizationException::class)
            ->and(fn () => recordSale($dataEntry, $this->customer, ['awal' => ['100', '15000']]))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CloseSale::class)->handle($munshi, $sale, ['reason' => 'Customer stopped']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CancelSale::class)->handle($munshi, $sale, ['reason' => 'Customer stopped']))->toThrow(AuthorizationException::class);

        // Money received with a sale also needs the right to record receipts.
        $manager = makeUser($this->kiln, Role::Manager);
        expect(recordSale($manager, $this->customer, ['doom' => ['100', '12000']], ['received' => '100', 'account_id' => (string) $this->cash->id])->receipt_entry_id)->not->toBeNull();
    });

    it('does not sell to a customer or a product of another kiln', function () {
        $otherKiln = makeSeededKiln();
        $otherOwner = makeUser($otherKiln, Role::Owner);
        actingInKiln($otherOwner, $otherKiln);
        $otherCustomer = makeParty($otherOwner, 'Other Customer');
        $otherAwal = product('awal')->id;
        actingInKiln($this->owner, $this->kiln);

        expect(validationErrors(fn () => recordSale($this->owner, $otherCustomer, ['awal' => ['100', '15000']]))['customer_id'])->toBe('Choose an active party with the customer role.');

        $errors = validationErrors(fn () => app(RecordSale::class)->handle($this->owner, [
            'sale_date' => now()->format('Y-m-d'), 'customer_id' => (string) $this->customer->id,
            'lines' => [['product_id' => (string) $otherAwal, 'quantity' => '1', 'rate' => '1']],
        ], (string) Str::uuid()));
        expect($errors)->toHaveKey('lines.0.product_id');
    });

    it('has the sales and bad debt accounts, and describes sales entries in words', function () {
        expect(LedgerAccount::bySystemKey(LedgerAccount::SALES)->code)->toBe('4100')
            ->and(LedgerAccount::bySystemKey(LedgerAccount::BAD_DEBTS)->code)->toBe('5910');

        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $trip = recordDispatch($this->owner, $sale, ['awal' => '2000'], ['driver_id' => (string) $this->driver->id, 'carriage' => '3000']);
        app(CloseSale::class)->handle($this->owner, $sale, ['reason' => 'Customer stopped']);

        expect(JournalPresenter::describe($sale->entry))->toBe('Sale to Demo Customer')
            ->and(JournalPresenter::describe($trip->carriageEntry))->toBe('Carriage of Demo Driver, charged to Demo Customer')
            ->and(JournalPresenter::describe($sale->fresh()->creditEntry))->toBe('Bricks not delivered: Demo Customer (Customer stopped)');
    });

    it('cannot be changed or deleted, and the transactions screen cannot reverse a sale', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        expect(fn () => $sale->update(['notes' => 'Changed later']))->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $sale->delete())->toThrow(LedgerIntegrityException::class)
            ->and(fn () => app(ReverseEntry::class)->handle($this->owner, $sale->entry, ['reason' => 'Try it here'], (string) Str::uuid()))->toThrow(Exception::class);
    });

    it('reports tampered sales, lines, deliveries and credit notes', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);
        $trip = recordDispatch($this->owner, $sale, ['awal' => '2000'], ['driver_id' => (string) $this->driver->id, 'carriage' => '3000']);
        app(CloseSale::class)->handle($this->owner, $sale, ['reason' => 'Customer stopped']);
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        DB::table('sale_lines')->where('sale_id', $sale->id)->update(['amount' => 1]);
        DB::table('sale_dispatch_lines')->where('sale_dispatch_id', $trip->id)->update(['quantity' => 9000000]);
        DB::table('sale_dispatches')->where('id', $trip->id)->update(['carriage' => 1]);
        DB::table('sales')->where('id', $sale->id)->update(['closed_at' => null]);

        $problems = implode("\n", app(LedgerVerifier::class)->verify());
        expect($problems)->toContain("Sale {$sale->number}: it does not match its invoice entry or its lines.")
            ->toContain("Sale {$sale->number}, line 1: the amount is not rate × quantity less the discount.")
            ->toContain("Delivery {$trip->number}: its stock movements do not match its lines.")
            ->toContain("Delivery {$trip->number}: its carriage does not match its entry.")
            ->toContain("Sale {$sale->number}, line 1: more was delivered than ordered.")
            ->toContain("Sale {$sale->number}: its credit note is inconsistent.");
    });
});
