<?php

use App\Accounting\LedgerQueries;
use App\Accounting\LedgerVerifier;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('cash book', function () {
    it('shows the balance before the period, each movement with a running balance, and the closing balance', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000'); // opened 30 days ago
        $bank = makeTreasuryAccount($this->owner, 'Bank', '10000', TreasuryKind::Bank);

        transferMoney($this->owner, $bank, $cash, '500', date: now()->subDays(20)->format('Y-m-d')); // before the period
        transferMoney($this->owner, $bank, $cash, '300', date: now()->subDays(5)->format('Y-m-d'));
        transferMoney($this->owner, $cash, $bank, '200', date: now()->subDays(2)->format('Y-m-d'));

        $book = app(LedgerQueries::class)->statement($cash->ledgerAccount, now()->subDays(10), now());

        expect($book['opening']->toDecimal())->toBe('1500.00')
            ->and(collect($book['rows'])->map(fn ($r) => [$r['in']->toDecimal(), $r['out']->toDecimal(), $r['balance']->toDecimal()])->all())
            ->toBe([['300.00', '0.00', '1800.00'], ['0.00', '200.00', '1600.00']])
            ->and($book['total_in']->toDecimal())->toBe('300.00')
            ->and($book['total_out']->toDecimal())->toBe('200.00')
            ->and($book['closing']->toDecimal())->toBe('1600.00')
            ->and($book['rows'][0]['counterparts'])->toBe('Bank');
    });

    it('treats a credit card the natural way: charges raise the amount owed', function () {
        $card = makeTreasuryAccount($this->owner, 'Card', '0', TreasuryKind::CreditCard);
        $cash = makeTreasuryAccount($this->owner, 'Cash', '0');

        transferMoney($this->owner, $card, $cash, '400'); // cash advance from the card

        $book = app(LedgerQueries::class)->statement($card->ledgerAccount, now()->subDays(1), now());
        expect($book['rows'][0]['in']->toDecimal())->toBe('400.00')
            ->and($book['closing']->toDecimal())->toBe('400.00')
            ->and(balanceOf($cash))->toBe('400.00');
    });
});

describe('trial balance', function () {
    it('always balances and respects the date', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $bank = makeTreasuryAccount($this->owner, 'Bank', '5000', TreasuryKind::Bank);
        recordCapital($this->owner, 'in', $bank, '2500');
        transferMoney($this->owner, $bank, $cash, '700', date: now()->subDays(3)->format('Y-m-d'));

        $queries = app(LedgerQueries::class);
        $today = $queries->trialBalance(now());
        $earlier = $queries->trialBalance(now()->subDays(10));

        expect($today['total_debit']->equals($today['total_credit']))->toBeTrue()
            ->and($today['total_debit']->toDecimal())->toBe('8500.00')
            ->and($earlier['total_debit']->toDecimal())->toBe('6000.00')
            ->and($earlier['total_debit']->equals($earlier['total_credit']))->toBeTrue();
    });

    it('only includes the current kiln', function () {
        makeTreasuryAccount($this->owner, 'Cash', '1000');

        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        app(CurrentKiln::class)->run($other, fn () => makeTreasuryAccount($otherOwner, 'Other cash', '999999'));

        expect(app(LedgerQueries::class)->trialBalance(now())['total_debit']->toDecimal())->toBe('1000.00');
    });
});

describe('ledger verifier', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $this->bank = makeTreasuryAccount($this->owner, 'Bank', '1000', TreasuryKind::Bank);
        transferMoney($this->owner, $this->bank, $this->cash, '250');
    });

    it('finds nothing wrong with books written by the system', function () {
        expect(app(LedgerVerifier::class)->verify())->toBe([]);
        $this->artisan('ledger:verify')->assertSuccessful();
    });

    it('detects an unbalanced entry written behind the system\'s back', function () {
        $line = DB::table('journal_lines')->orderByDesc('id')->first();
        DB::table('journal_lines')->where('id', $line->id)->update(['debit' => $line->debit > 0 ? $line->debit + 1 : 0, 'credit' => $line->credit > 0 ? $line->credit + 1 : 0]);

        expect(app(LedgerVerifier::class)->verify())->not->toBe([]);
        $this->artisan('ledger:verify')->assertFailed();
    });

    it('detects an opening balance that does not match its entry', function () {
        DB::table('treasury_accounts')->where('id', $this->cash->id)->update(['opening_balance' => 123]);

        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('opening balance');
    });

    it('detects a broken reversal link', function () {
        DB::table('journal_entries')->where('type', 'transfer')->update(['status' => 'reversed']);

        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('no reversal entry');
    });
});
