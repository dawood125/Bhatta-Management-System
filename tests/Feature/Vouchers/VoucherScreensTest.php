<?php

use App\Actions\Accounting\ReverseEntry;
use App\Actions\Parties\SaveParty;
use App\Actions\Parties\SetPartyArchived;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Livewire\Accounts\CashBook;
use App\Livewire\Parties\PartyStatement;
use App\Livewire\Vouchers\VoucherForm;
use App\Livewire\Vouchers\VoucherIndex;
use App\Livewire\Vouchers\VoucherShow;
use App\Models\JournalEntry;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash in hand', '70000');
    $this->supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier], '40000', SaveParty::WE_OWE_THEM, ['phone' => '0301 7654321']);
    $this->customer = makeParty($this->owner, 'Builder Customer', [PartyRole::Customer], '10000');
});

describe('access', function () {
    it('opens the screens for the right roles only', function (Role $role, int $list, int $create) {
        $voucher = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '10']]);
        $user = makeUser($this->kiln, $role);

        $this->actingAs($user)->get('/vouchers')->assertStatus($list);
        $this->actingAs($user)->get("/vouchers/{$voucher->id}")->assertStatus($list);
        $this->actingAs($user)->get('/vouchers/new/payment')->assertStatus($create);
        $this->actingAs($user)->get('/vouchers/new/receipt')->assertStatus($create);
    })->with([
        'owner' => [Role::Owner, 200, 200],
        'manager' => [Role::Manager, 200, 200],
        'munshi' => [Role::Munshi, 200, 200],
        'viewer' => [Role::Viewer, 403, 403],
        'data entry' => [Role::DataEntry, 403, 403],
    ]);

    it('returns 404 for an unknown voucher kind, a non-voucher entry, or another kiln\'s voucher', function () {
        $transfer = transferMoney($this->owner, $this->cash, makeTreasuryAccount($this->owner, 'Bank', '0', TreasuryKind::Bank), '5');

        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        $foreign = app(CurrentKiln::class)->run($other, function () use ($otherOwner) {
            $cash = makeTreasuryAccount($otherOwner, 'Their cash', '100');
            $party = makeParty($otherOwner, 'Their supplier', [PartyRole::Supplier]);

            return recordVoucher($otherOwner, JournalType::Payment, $cash, [[$party, '10']]);
        });
        app(CurrentKiln::class)->set($this->kiln);

        $this->actingAs($this->owner)->get('/vouchers/new/expense')->assertNotFound();
        $this->actingAs($this->owner)->get("/vouchers/{$transfer->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/vouchers/{$foreign->id}")->assertNotFound();
    });
});

describe('voucher form', function () {
    it('records the acceptance example through the screen and opens the voucher', function () {
        $driver = makeParty($this->owner, 'Driver B', [PartyRole::Driver], '5000', SaveParty::WE_OWE_THEM);

        $component = Livewire::test(VoucherForm::class, ['kind' => 'payment'])
            ->assertSet('form.account_id', (string) $this->cash->id)
            ->set('form.lines.0.party_id', (string) $this->supplier->id)
            ->assertSee('40,000.00') // the supplier's balance appears next to the line
            ->set('form.lines.0.amount', '30,000')
            ->call('addLine')
            ->set('form.lines.1.party_id', (string) $driver->id)
            ->set('form.lines.1.amount', '5000')
            ->assertSee('35,000.00') // the total and the cash left after the voucher
            ->call('save')
            ->assertHasNoErrors();

        $voucher = JournalEntry::query()->where('type', JournalType::Payment)->sole();
        $component->assertRedirect(route('vouchers.show', $voucher));

        expect(balanceOf($this->cash))->toBe('35000.00')
            ->and($this->supplier->balance()->toDecimal())->toBe('-10000.00')
            ->and($driver->balance()->toDecimal())->toBe('0.00');
    });

    it('records a receipt with a line from one of our own accounts', function () {
        $bank = makeTreasuryAccount($this->owner, 'Bank', '50000', TreasuryKind::Bank);

        Livewire::test(VoucherForm::class, ['kind' => 'receipt'])
            ->set('form.lines.0.party_id', (string) $this->customer->id)
            ->set('form.lines.0.amount', '4000')
            ->call('addLine')
            ->set('form.lines.1.type', 'account')
            ->set('form.lines.1.account_id', (string) $bank->id)
            ->set('form.lines.1.amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        expect(balanceOf($this->cash))->toBe('75000.00')
            ->and(balanceOf($bank))->toBe('49000.00')
            ->and($this->customer->balance()->toDecimal())->toBe('6000.00');
    });

    it('shows errors on the right line', function () {
        Livewire::test(VoucherForm::class, ['kind' => 'payment'])
            ->set('form.lines.0.amount', '100')
            ->call('addLine')
            ->set('form.lines.1.party_id', (string) $this->supplier->id)
            ->set('form.lines.1.amount', 'abc')
            ->call('save')
            ->assertHasErrors(['form.lines.0.party_id', 'form.lines.1.amount'])
            ->assertHasNoErrors(['form.lines.1.party_id']);

        expect(JournalEntry::query()->where('type', JournalType::Payment)->count())->toBe(0);
    });

    it('clears a line\'s error as soon as that field is corrected', function () {
        Livewire::test(VoucherForm::class, ['kind' => 'payment'])
            ->set('form.lines.0.amount', 'abc')
            ->call('save')
            ->assertHasErrors(['form.lines.0.party_id', 'form.lines.0.amount'])
            ->set('form.lines.0.party_id', (string) $this->supplier->id)
            ->assertHasNoErrors(['form.lines.0.party_id'])
            ->assertHasErrors(['form.lines.0.amount'])
            ->set('form.lines.0.amount', '100')
            ->assertHasNoErrors();
    });

    it('warns when the account would go below zero, and refuses to save', function () {
        Livewire::test(VoucherForm::class, ['kind' => 'payment'])
            ->set('form.lines.0.party_id', (string) $this->supplier->id)
            ->set('form.lines.0.amount', '70,000.01')
            ->assertSee('Not enough money in this account.')
            ->call('save')
            ->assertHasErrors(['form.account_id']);
    });

    it('adds and removes lines, and clears a line when its type changes', function () {
        Livewire::test(VoucherForm::class, ['kind' => 'payment'])
            ->set('form.lines.0.party_id', (string) $this->supplier->id)
            ->set('form.lines.0.type', 'account')
            ->assertSet('form.lines.0.party_id', '')
            ->call('addLine')
            ->call('addLine')
            ->assertCount('form.lines', 3)
            ->assertSet('form.lines.2.type', 'account') // a new line copies the type of the one above
            ->call('removeLine', 1)
            ->assertCount('form.lines', 2)
            ->call('removeLine', 0)
            ->call('removeLine', 0)
            ->assertCount('form.lines', 1); // the last line is replaced by a blank one
    });

    it('sends the party list with the first render only', function () {
        $component = Livewire::test(VoucherForm::class, ['kind' => 'payment']);
        expect($component->html())->toContain('id="party-options"')->toContain('Coal Supplier')->toContain('0301 7654321');

        $component->set('form.memo', 'x');
        expect($component->html())->not->toContain('id="party-options"');
    });

    it('leaves archived parties out of the party list', function () {
        $settled = makeParty($this->owner, 'Settled Old Party', [PartyRole::Supplier]);
        app(SetPartyArchived::class)->handle($this->owner, $settled, true);

        expect(Livewire::test(VoucherForm::class, ['kind' => 'payment'])->html())->not->toContain('Settled Old Party');
    });
});

describe('voucher list and page', function () {
    it('totals payments and receipts for the period, leaving out reversed vouchers', function () {
        recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '3000']]);
        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '2500']]);
        $wrong = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '9999']]);
        app(ReverseEntry::class)->handle($this->owner, $wrong, ['reason' => 'Wrong amount'], (string) Str::uuid());

        $component = Livewire::test(VoucherIndex::class);
        $totals = $component->instance()->totals;

        expect($totals['paid']->toDecimal())->toBe('3000.00')
            ->and($totals['received']->toDecimal())->toBe('2500.00')
            ->and($totals['count'])->toBe(2);

        $component->assertSee('Coal Supplier')->assertSee('Builder Customer')->assertSee('Reversed');
    });

    it('filters by type, account, search and period', function () {
        $bank = makeTreasuryAccount($this->owner, 'Bank', '5000', TreasuryKind::Bank);
        $pay = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '100']]);
        $rec = recordVoucher($this->owner, JournalType::Receipt, $bank, [[$this->customer, '200', 'Cheque 4411']]);
        $old = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '300']], ['entry_date' => now()->subDays(20)->format('Y-m-d')]);

        Livewire::test(VoucherIndex::class)
            ->set('from', now()->subDays(5)->format('Y-m-d'))
            ->assertSee($pay->number)->assertSee($rec->number)->assertDontSee($old->number)
            ->set('type', 'receipt')->assertSee($rec->number)->assertDontSee($pay->number)
            ->set('type', '')->set('account', (string) $this->cash->id)->assertSee($pay->number)->assertDontSee($rec->number)
            ->set('account', '')->set('search', '4411')->assertSee($rec->number)->assertDontSee($pay->number)
            ->set('search', 'Coal')->assertSee($pay->number)->assertDontSee($rec->number);
    });

    it('shows a voucher with its lines and reverses it (owner and manager only)', function () {
        $voucher = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '1500', 'Coal advance']], ['memo' => 'Paid at the kiln']);

        Livewire::test(VoucherShow::class, ['entry' => $voucher])
            ->assertSee('Payment voucher')
            ->assertSee($voucher->number)
            ->assertSee('Coal Supplier')
            ->assertSee('Coal advance')
            ->assertSee('Paid at the kiln')
            ->assertSee('1,500.00')
            ->call('confirmReverse')
            ->set('reverseForm.reason', 'Wrong supplier')
            ->call('reverse')
            ->assertHasNoErrors()
            ->assertSee('Wrong supplier');

        expect($voucher->fresh()->isReversed())->toBeTrue()->and(balanceOf($this->cash))->toBe('70000.00');

        $other = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '10']]);
        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);
        Livewire::test(VoucherShow::class, ['entry' => $other])->assertDontSee('Reverse voucher')->call('confirmReverse')->assertForbidden();
    });

    it('describes vouchers by party name on statements and cash books', function () {
        recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '700', 'Second trolley']]);

        Livewire::test(PartyStatement::class, ['party' => $this->supplier])
            ->assertSee('Paid to Coal Supplier')
            ->assertSee('Second trolley');

        Livewire::test(CashBook::class, ['account' => $this->cash])
            ->assertSee('Paid to Coal Supplier');
    });
});
