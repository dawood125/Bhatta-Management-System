<?php

use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Livewire\Accounts\AccountIndex;
use App\Livewire\Accounts\CashBook;
use App\Livewire\Accounts\TransactionIndex;
use App\Livewire\Reports\TrialBalance;
use App\Models\JournalEntry;
use App\Models\TreasuryAccount;
use App\Support\Kiln\CurrentKiln;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('who can see what', function () {
    it('opens the money pages for the right roles only', function (Role $role, array $expected) {
        $user = makeUser($this->kiln, $role);
        $cash = makeTreasuryAccount($this->owner, 'Cash '.$role->value, '10');

        foreach (['/accounts' => 'accounts', "/accounts/{$cash->id}/book" => 'accounts', '/transactions' => 'accounts', '/reports/trial-balance' => 'reports'] as $url => $area) {
            $this->actingAs($user)->get($url)->assertStatus($expected[$area]);
        }
    })->with([
        'owner' => [Role::Owner, ['accounts' => 200, 'reports' => 200]],
        'manager' => [Role::Manager, ['accounts' => 200, 'reports' => 200]],
        'munshi' => [Role::Munshi, ['accounts' => 200, 'reports' => 403]],
        'data entry' => [Role::DataEntry, ['accounts' => 403, 'reports' => 403]],
        'viewer' => [Role::Viewer, ['accounts' => 403, 'reports' => 403]],
    ]);

    it('returns 404 for a cash book of another kiln', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        $foreign = app(CurrentKiln::class)->run($other, fn () => makeTreasuryAccount($otherOwner, 'Their cash', '5000'));

        $this->actingAs($this->owner)->get("/accounts/{$foreign->id}/book")->assertNotFound();
    });

    it('shows only the buttons a person may use', function () {
        $munshi = makeUser($this->kiln, Role::Munshi);
        actingInKiln($munshi, $this->kiln);

        Livewire::test(AccountIndex::class)
            ->assertSee('Transfer')
            ->assertDontSee('Add account')
            ->assertDontSee('Owner capital')
            ->call('createAccount')->assertForbidden();

        Livewire::test(AccountIndex::class)->call('openCapital', 'in')->assertForbidden();
        Livewire::test(TransactionIndex::class)->call('confirmReverse', 1)->assertForbidden();
    });
});

describe('cash and bank screen', function () {
    it('adds an account with its opening balance', function () {
        Livewire::test(AccountIndex::class)
            ->assertSee('No accounts yet')
            ->call('createAccount')
            ->set('accountForm.kind', TreasuryKind::Bank->value)
            ->set('accountForm.name', 'Meezan Bank')
            ->set('accountForm.name_ur', 'میزان بینک')
            ->set('accountForm.opening_balance', 'one lakh')
            ->call('saveAccount')
            ->assertHasErrors('accountForm.opening_balance')
            ->set('accountForm.opening_balance', '1,50,000') // lakh-style grouping, as commonly written in Pakistan
            ->call('saveAccount')
            ->assertHasNoErrors()
            ->assertSee('Meezan Bank')
            ->assertSee('150,000.00');

        expect(balanceOf(TreasuryAccount::query()->where('name', 'Meezan Bank')->sole()))->toBe('150000.00');
    });

    it('records a transfer and never twice on a double submit', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '0');
        $bank = makeTreasuryAccount($this->owner, 'Bank', '50000', TreasuryKind::Bank);

        $component = Livewire::test(AccountIndex::class)
            ->call('openTransfer', $bank->id)
            ->set('transferForm.to_account_id', (string) $cash->id)
            ->set('transferForm.amount', '20000');

        $key = $component->get('formKey');
        $component->call('saveTransfer')->assertHasNoErrors()->assertDispatched('toast');

        // A second click that reaches the server with the same form key:
        $component->set('formKey', $key)->set('showTransferForm', true)->call('saveTransfer')->assertHasNoErrors();

        expect(JournalEntry::query()->where('type', 'transfer')->count())->toBe(1)
            ->and(balanceOf($cash))->toBe('20000.00');
    });

    it('shows a clear error when the money is not there', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '100');
        $bank = makeTreasuryAccount($this->owner, 'Bank', '0', TreasuryKind::Bank);

        Livewire::test(AccountIndex::class)
            ->call('openTransfer', $cash->id)
            ->set('transferForm.to_account_id', (string) $bank->id)
            ->set('transferForm.amount', '150')
            ->call('saveTransfer')
            ->assertHasErrors('transferForm.amount')
            ->assertSet('showTransferForm', true);
    });

    it('records owner capital', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '0');

        Livewire::test(AccountIndex::class)
            ->call('openCapital', 'in')
            ->set('capitalForm.account_id', (string) $cash->id)
            ->set('capitalForm.amount', '75000')
            ->call('saveCapital')
            ->assertHasNoErrors();

        expect(balanceOf($cash))->toBe('75000.00');
    });
});

describe('transactions screen', function () {
    it('lists transactions, shows their lines, and reverses with a reason', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $bank = makeTreasuryAccount($this->owner, 'Bank', '0', TreasuryKind::Bank);
        $transfer = transferMoney($this->owner, $cash, $bank, '400');

        Livewire::test(TransactionIndex::class)
            ->assertSee($transfer->number)
            ->call('toggle', $transfer->id)
            ->assertSee('Debit')
            ->call('confirmReverse', $transfer->id)
            ->set('reverseForm.reason', 'Wrong account chosen')
            ->call('reverse')
            ->assertHasNoErrors()
            ->assertSee('Reversed');

        expect($transfer->fresh()->isReversed())->toBeTrue()
            ->and(balanceOf($cash))->toBe('1000.00');
    });

    it('filters by type and searches by number', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $bank = makeTreasuryAccount($this->owner, 'Bank', '0', TreasuryKind::Bank);
        $transfer = transferMoney($this->owner, $cash, $bank, '400');

        Livewire::test(TransactionIndex::class)
            ->set('type', 'opening_balance')->assertDontSee($transfer->number)
            ->set('type', '')->set('search', $transfer->number)->assertSee($transfer->number);
    });
});

describe('cash book and trial balance screens', function () {
    it('shows the cash book for a date range and rejects an impossible range', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000');

        Livewire::test(CashBook::class, ['account' => $cash])
            ->call('setRange', 'all')
            ->assertSee('Opening balance')
            ->assertSee('1,000.00')
            ->set('from', now()->format('Y-m-d'))
            ->set('to', now()->subDay()->format('Y-m-d'))
            ->assertSee('Choose a valid date range');
    });

    it('says the trial balance is balanced', function () {
        makeTreasuryAccount($this->owner, 'Cash', '1000');

        Livewire::test(TrialBalance::class)
            ->assertSee('Balanced: total debits equal total credits.')
            ->set('asOf', 'not-a-date')
            ->assertSee('Choose a valid date');
    });
});
