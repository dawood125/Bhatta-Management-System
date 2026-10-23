<?php

use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Livewire\Parties\PartyStatement;
use App\Livewire\Production\StockOverview;
use App\Livewire\Sales\DispatchIndex;
use App\Livewire\Sales\DriversDue;
use App\Livewire\Sales\EmailLogIndex;
use App\Livewire\Sales\SaleForm;
use App\Livewire\Sales\SaleIndex;
use App\Livewire\Sales\SaleShow;
use App\Livewire\Settings\KilnProfile;
use App\Livewire\Vouchers\VoucherForm;
use App\Models\EmailLog;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Support\Kiln\CurrentKiln;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
    $this->customer = makeParty($this->owner, 'Demo Customer');
    $this->driver = makeParty($this->owner, 'Demo Driver', [PartyRole::Driver]);
    makeSaleableStock($this->owner, ['awal' => '8000', 'doom' => '2000']);
});

describe('access', function () {
    it('opens the sales pages for those who may see them', function (Role $role, bool $sees, bool $records) {
        $user = makeUser($this->kiln, $role);
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);

        foreach (['/sales', '/sales/deliveries', '/sales/drivers', '/sales/emails', "/sales/{$sale->id}", "/sales/{$sale->id}/invoice"] as $url) {
            $this->actingAs($user)->get($url)->assertStatus($sees ? 200 : 403);
        }
        $this->actingAs($user)->get('/sales/new')->assertStatus($records ? 200 : 403);

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk();
        $sees ? $dashboard->assertSee(route('sales.index'), false) : $dashboard->assertDontSee(route('sales.index'), false);
    })->with([
        'owner' => [Role::Owner, true, true],
        'manager' => [Role::Manager, true, true],
        'munshi' => [Role::Munshi, true, true],
        'data entry' => [Role::DataEntry, false, false],
        'viewer' => [Role::Viewer, true, false],
    ]);

    it('shows no buttons to a viewer, and no corrections to the munshi', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);
        recordDispatch($this->owner, $sale, ['awal' => '500']);

        $this->actingAs(makeUser($this->kiln, Role::Viewer));
        $this->get('/sales')->assertOk()->assertDontSee('New sale');
        $this->get("/sales/{$sale->id}")->assertOk()->assertSee('Invoice PDF')
            ->assertDontSee('Record delivery')->assertDontSee('Receive payment')->assertDontSee('Email invoice')->assertDontSee('Close the rest');

        $this->actingAs(makeUser($this->kiln, Role::Munshi));
        $this->get("/sales/{$sale->id}")->assertOk()->assertSee('Record delivery')->assertSee('Receive payment')
            ->assertDontSee('Close the rest')->assertDontSee('Cancel sale');
    });

    it('does not show another kiln\'s sale or invoice', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        actingInKiln($otherOwner, $other);
        makeSaleableStock($otherOwner, ['awal' => '100']);
        $stranger = recordSale($otherOwner, makeParty($otherOwner, 'Elsewhere'), ['awal' => ['100', '15000']]);

        actingInKiln($this->owner, $this->kiln);
        $this->actingAs($this->owner)->get("/sales/{$stranger->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/sales/{$stranger->id}/invoice")->assertNotFound();
    });
});

describe('sales', function () {
    it('records a sale from the form and opens it', function () {
        $agent = makeParty($this->owner, 'Demo Agent', [PartyRole::Agent]);
        $customer = updateParty($this->owner, $this->customer, ['sales_agent_id' => (string) $agent->id]);

        Livewire::test(SaleForm::class)
            ->set('form.customer_id', (string) $customer->id)
            ->assertSet('form.agent_id', (string) $agent->id)
            ->set('form.lines.0.product_id', (string) product('awal')->id)
            ->assertSee('Free to sell: 8,000')
            ->set('form.lines.0.quantity', '5000')
            ->set('form.lines.0.rate', '15000')
            ->assertSee('75,000.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('sales.show', Sale::query()->sole()));

        expect(Sale::query()->sole()->agent_id)->toBe($agent->id);
    });

    it('shows the free-stock error on the line, and takes the bricks and money at once', function () {
        Livewire::test(SaleForm::class)
            ->set('form.customer_id', (string) $this->customer->id)
            ->set('form.lines.0.product_id', (string) product('awal')->id)
            ->set('form.lines.0.quantity', '9000')
            ->set('form.lines.0.rate', '15000')
            ->call('save')
            ->assertHasErrors(['form.lines.0.quantity'])
            ->assertSee('Only 8,000 free to sell')
            ->set('form.lines.0.quantity', '2000')
            ->set('form.deliver_now', true)
            ->set('form.driver_id', (string) $this->driver->id)
            ->set('form.carriage', '3000')
            ->assertSee('33,000.00')
            ->set('form.received', '33000')
            ->set('form.account_id', (string) $this->cash->id)
            ->call('save')
            ->assertHasNoErrors();

        $sale = Sale::query()->sole();
        expect($sale->status)->toBe(SaleStatus::Completed)
            ->and($sale->receipt_entry_id)->not->toBeNull()
            ->and(partyBalance($this->customer))->toBe('0.00');
    });

    it('lists sales with what is still to be paid, and filters by payment', function () {
        $paid = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']], ['received' => '15000', 'account_id' => (string) $this->cash->id]);
        $unpaid = recordSale($this->owner, makeParty($this->owner, 'Second Customer'), ['doom' => ['1000', '12000']]);

        Livewire::test(SaleIndex::class)
            ->assertSee($paid->number)->assertSee($unpaid->number)
            ->assertSee('27,000.00')
            ->set('payment', 'unpaid')
            ->assertSee($unpaid->number)->assertDontSee($paid->number)
            ->set('payment', 'paid')
            ->assertSee($paid->number)->assertDontSee($unpaid->number);
    });

    it('records a delivery, a payment, reverses the delivery and closes the rest from the sale page', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        $page = Livewire::test(SaleShow::class, ['sale' => $sale])
            ->call('openDelivery')
            ->assertSet('deliveryForm.lines.0.quantity', '5000')
            ->set('deliveryForm.lines.0.quantity', '2000')
            ->set('deliveryForm.driver_id', (string) $this->driver->id)
            ->set('deliveryForm.carriage', '2500')
            ->call('saveDelivery')
            ->assertHasNoErrors()
            ->assertSet('showDelivery', false);

        $trip = SaleDispatch::query()->sole();
        expect($trip->carriage->toDecimal())->toBe('2500.00');

        $page->call('openPayment')
            ->assertSet('paymentForm.amount', '77500.00')
            ->set('paymentForm.amount', '')
            ->call('savePayment')
            ->assertHasErrors(['paymentForm.amount'])
            ->set('paymentForm.amount', '20000')
            ->call('savePayment')
            ->assertHasNoErrors();
        expect(JournalEntry::query()->where('type', JournalType::Receipt)->count())->toBe(1);

        $page->call('confirmReverse', $trip->id)
            ->set('reasonForm.reason', 'Bricks came back')
            ->call('saveReason')
            ->assertHasNoErrors();
        expect($trip->fresh()->isReversed())->toBeTrue();

        recordDispatch($this->owner, $sale->fresh(), ['awal' => '1000']);
        $page->call('confirmClose')
            ->set('reasonForm.reason', 'Customer stopped')
            ->call('saveReason')
            ->assertHasNoErrors();
        expect($sale->fresh()->isClosed())->toBeTrue();
    });

    it('cancels a sale with nothing delivered, and asks for addresses before emailing', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);

        Livewire::test(SaleShow::class, ['sale' => $sale])
            ->call('emailInvoice')
            ->assertHasErrors(['email'])
            ->call('confirmCancel')
            ->set('reasonForm.reason', 'Abc')
            ->call('saveReason')
            ->assertHasErrors(['reasonForm.reason'])
            ->set('reasonForm.reason', 'Entered twice')
            ->call('saveReason')
            ->assertHasNoErrors();

        expect($sale->fresh()->isCancelled())->toBeTrue();
    });
});

describe('deliveries, drivers and emails', function () {
    it('lists deliveries and the drivers owed carriage, with a payment voucher filled in', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['2000', '15000']]);
        recordDispatch($this->owner, $sale, ['awal' => '2000'], ['driver_id' => (string) $this->driver->id, 'vehicle' => 'LHR-1234', 'carriage' => '3000']);

        Livewire::test(DispatchIndex::class)->assertSee('LHR-1234')->assertSee('2,000')->assertSee('3,000.00');

        $link = route('vouchers.create', ['kind' => 'payment', 'lines' => $this->driver->id.':3000.00']);
        Livewire::test(DriversDue::class)->assertSee('Demo Driver')->assertSee('3,000.00')->assertSee(e($link), false);

        Livewire::withQueryParams(['lines' => $this->driver->id.':3000.00'])
            ->test(VoucherForm::class, ['kind' => 'payment'])
            ->assertSet('form.lines.0.party_id', (string) $this->driver->id)
            ->assertSet('form.lines.0.amount', '3000.00');
    });

    it('lists invoice emails and sends one again', function () {
        app(CurrentKiln::class)->get()->forceFill(['sales_email_enabled' => true, 'sales_email_to' => 'owner@example.com'])->save();
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);

        Livewire::test(EmailLogIndex::class)
            ->assertSee($sale->number)->assertSee('owner@example.com')->assertSee('Sent')
            ->call('resend', $sale->id)
            ->assertHasNoErrors();

        expect(EmailLog::query()->count())->toBe(2);
    });
});

describe('other screens', function () {
    it('shows bricks reserved for sales and free to sell on the stock page', function () {
        recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        Livewire::test(StockOverview::class)->assertSee('Reserved for sales')->assertSee('5,000')->assertSee('3,000');
    });

    it('lets the owner write off a customer balance from the statement, and offers the PDF', function () {
        recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);

        Livewire::test(PartyStatement::class, ['party' => $this->customer])
            ->assertSee(route('parties.statement.pdf', ['party' => $this->customer]), false)
            ->call('openWriteOff')
            ->assertSet('writeOffForm.amount', '15000.00')
            ->set('writeOffForm.reason', 'Customer left the area')
            ->call('writeOff')
            ->assertHasNoErrors();
        expect(partyBalance($this->customer))->toBe('0.00');

        $manager = makeUser($this->kiln, Role::Manager);
        $this->actingAs($manager)->get("/parties/{$this->customer->id}")->assertOk()->assertDontSee('Write off');
        $this->actingAs($manager)->get("/parties/{$this->customer->id}/statement.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs(makeUser($this->kiln, Role::DataEntry))->get("/parties/{$this->customer->id}/statement.pdf")->assertForbidden();
    });

    it('serves the invoice as a PDF to view or download, in English and Urdu', function () {
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);

        $view = $this->get("/sales/{$sale->id}/invoice")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($view->headers->get('Content-Disposition'))->toBe("inline; filename=\"{$sale->number}.pdf\"")
            ->and(str_starts_with((string) $view->getContent(), '%PDF'))->toBeTrue()
            ->and($this->get("/sales/{$sale->id}/invoice?download=1")->headers->get('Content-Disposition'))->toStartWith('attachment;');

        $this->owner->forceFill(['locale' => 'ur'])->save();
        expect(str_starts_with((string) $this->get("/sales/{$sale->id}/invoice")->assertOk()->getContent(), '%PDF'))->toBeTrue();
    });

    it('saves sales emails and the invoice note on the kiln profile', function () {
        Livewire::test(KilnProfile::class)
            ->set('salesForm.sales_email_enabled', true)
            ->set('salesForm.sales_email_to', '')
            ->call('saveSales')
            ->assertHasErrors(['salesForm.sales_email_to'])
            ->set('salesForm.sales_email_to', 'owner@example.com')
            ->set('salesForm.invoice_note', 'Thank you')
            ->call('saveSales')
            ->assertHasNoErrors();

        expect(app(CurrentKiln::class)->get()->fresh()->salesEmailRecipients())->toBe(['owner@example.com']);
    });
});
