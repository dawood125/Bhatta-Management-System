<?php

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerQueries;
use App\Accounting\LedgerVerifier;
use App\Accounting\PendingLine;
use App\Actions\Parties\SaveParty;
use App\Actions\Parties\SetPartyArchived;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->queries = app(LedgerQueries::class);
});

function arBalance(): string
{
    return app(LedgerQueries::class)->balance(LedgerAccount::bySystemKey(LedgerAccount::RECEIVABLES))->toDecimal();
}

function apBalance(): string
{
    return app(LedgerQueries::class)->balance(LedgerAccount::bySystemKey(LedgerAccount::PAYABLES))->toDecimal();
}

describe('acceptance example (plan M3)', function () {
    it('a customer with an opening receivable of 10,000 shows 10,000 receivable, matching the receivable account', function () {
        $customer = makeParty($this->owner, 'Haji Bashir', [PartyRole::Customer], '10,000');

        $statement = $this->queries->partyStatement($customer, now()->subYear(), now());

        expect($customer->balance()->toDecimal())->toBe('10000.00')
            ->and($statement['closing']->toDecimal())->toBe('10000.00')
            ->and($statement['total_debit']->toDecimal())->toBe('10000.00')
            ->and(arBalance())->toBe('10000.00');
    });

    it('keeps the sum of all party balances equal to receivables minus payables', function () {
        makeParty($this->owner, 'Customer A', [PartyRole::Customer], '10000');
        makeParty($this->owner, 'Customer B', [PartyRole::Customer], '2500.50');
        makeParty($this->owner, 'Soil supplier', [PartyRole::Supplier], '7000', SaveParty::WE_OWE_THEM);
        makeParty($this->owner, 'Driver Aslam', [PartyRole::Driver], '300', SaveParty::WE_OWE_THEM);

        $totals = $this->queries->partyTotals();

        expect($totals['receivable']->toDecimal())->toBe('12500.50')
            ->and($totals['payable']->toDecimal())->toBe('7300.00')
            ->and(arBalance())->toBe('12500.50')
            ->and(apBalance())->toBe('7300.00')
            ->and($this->queries->trialBalance(now())['total_debit']->equals($this->queries->trialBalance(now())['total_credit']))->toBeTrue();
    });
});

describe('opening balances', function () {
    it('posts "we owe them" to payables', function () {
        $supplier = makeParty($this->owner, 'Coal supplier', [PartyRole::Supplier], '45000', SaveParty::WE_OWE_THEM);

        $entry = JournalEntry::query()->findOrFail($supplier->opening_entry_id);
        $line = $entry->lines()->whereNotNull('party_id')->sole();

        expect($supplier->balance()->toDecimal())->toBe('-45000.00')
            ->and($line->credit->toDecimal())->toBe('45000.00')
            ->and($line->ledger_account_id)->toBe(LedgerAccount::bySystemKey(LedgerAccount::PAYABLES)->id)
            ->and($entry->type)->toBe(JournalType::OpeningBalance);
    });

    it('posts nothing when nothing is owed', function () {
        $party = makeParty($this->owner, 'New customer');

        expect($party->opening_entry_id)->toBeNull()->and($party->balance()->isZero())->toBeTrue();
    });

    it('changes the opening balance and its direction through a reversal on the original date', function () {
        $party = makeParty($this->owner, 'Customer', [PartyRole::Customer], '5000');
        $oldEntry = JournalEntry::query()->findOrFail($party->opening_entry_id);

        $party = updateParty($this->owner, $party, ['opening_amount' => '1200', 'opening_direction' => SaveParty::WE_OWE_THEM]);

        $reversal = JournalEntry::query()->where('reversal_of_id', $oldEntry->id)->sole();
        expect($party->balance()->toDecimal())->toBe('-1200.00')
            ->and($oldEntry->fresh()->isReversed())->toBeTrue()
            ->and($reversal->entry_date->format('Y-m-d'))->toBe($oldEntry->entry_date->format('Y-m-d'))
            ->and(arBalance())->toBe('0.00')
            ->and(apBalance())->toBe('1200.00');
    });

    it('does not move the opening date after existing transactions', function () {
        $party = makeParty($this->owner, 'Customer', [PartyRole::Customer], '1000');
        $cash = makeTreasuryAccount($this->owner, 'Cash', '0');

        // A later module's transaction (e.g. a receipt) dated 10 days ago:
        app(JournalPoster::class)->post(JournalType::Transfer, now()->subDays(10), [
            PendingLine::debit($cash->ledgerAccount, Money::fromRupees('400')),
            PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::RECEIVABLES), Money::fromRupees('400'))->forParty($party),
        ], (string) Str::uuid(), $this->owner);

        updateParty($this->owner, $party, ['opening_date' => now()->subDays(5)->format('Y-m-d')]);
    })->throws(ValidationException::class);
});

describe('ledger rules for parties', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $this->customer = makeParty($this->owner, 'Customer');
        $this->ar = LedgerAccount::bySystemKey(LedgerAccount::RECEIVABLES);
    });

    it('requires a party on receivable and payable lines', function () {
        app(JournalPoster::class)->post(JournalType::Transfer, now(), [
            PendingLine::debit($this->ar, Money::fromRupees('5')),
            PendingLine::credit($this->cash->ledgerAccount, Money::fromRupees('5')),
        ], (string) Str::uuid(), $this->owner);
    })->throws(LedgerIntegrityException::class, 'must name a party');

    it('refuses a party on other accounts', function () {
        app(JournalPoster::class)->post(JournalType::Transfer, now(), [
            PendingLine::debit($this->ar, Money::fromRupees('5'))->forParty($this->customer),
            PendingLine::credit($this->cash->ledgerAccount, Money::fromRupees('5'))->forParty($this->customer),
        ], (string) Str::uuid(), $this->owner);
    })->throws(LedgerIntegrityException::class, 'cannot name a party');

    it('refuses a party of another kiln', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        $foreign = app(CurrentKiln::class)->run($other, fn () => makeParty($otherOwner, 'Their customer'));
        app(CurrentKiln::class)->set($this->kiln);

        app(JournalPoster::class)->post(JournalType::Transfer, now(), [
            PendingLine::debit($this->ar, Money::fromRupees('5'))->forParty($foreign),
            PendingLine::credit($this->cash->ledgerAccount, Money::fromRupees('5')),
        ], (string) Str::uuid(), $this->owner);
    })->throws(LedgerIntegrityException::class);

    it('keeps the party on reversal lines', function () {
        $entry = app(JournalPoster::class)->post(JournalType::Transfer, now(), [
            PendingLine::debit($this->ar, Money::fromRupees('250'))->forParty($this->customer),
            PendingLine::credit($this->cash->ledgerAccount, Money::fromRupees('250')),
        ], (string) Str::uuid(), $this->owner);

        $reversal = app(JournalPoster::class)->reverse($entry, now(), 'Wrong customer', (string) Str::uuid(), $this->owner);

        expect($reversal->lines()->whereNotNull('party_id')->sole()->party_id)->toBe($this->customer->id)
            ->and($this->customer->balance()->isZero())->toBeTrue();
    });

    it('lets the verifier catch a receivable line without a party or a wrong opening', function () {
        $party = makeParty($this->owner, 'Customer 2', [PartyRole::Customer], '900');
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        DB::table('parties')->where('id', $party->id)->update(['opening_balance' => 1]);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('Party "Customer 2"');

        DB::table('parties')->where('id', $party->id)->update(['opening_balance' => 90000]);
        DB::table('journal_lines')->where('party_id', $party->id)->update(['party_id' => null]);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('party missing');
    });
});

describe('statements', function () {
    it('shows the balance brought forward and a running balance', function () {
        $customer = makeParty($this->owner, 'Customer', [PartyRole::Customer], '1000'); // opened 30 days ago
        $cash = makeTreasuryAccount($this->owner, 'Cash', '0');
        $ar = LedgerAccount::bySystemKey(LedgerAccount::RECEIVABLES);

        // Payment received 20 days ago (before the period) and 3 days ago (inside it):
        foreach ([[20, '300'], [3, '200']] as [$daysAgo, $amount]) {
            app(JournalPoster::class)->post(JournalType::Transfer, now()->subDays($daysAgo), [
                PendingLine::debit($cash->ledgerAccount, Money::fromRupees($amount)),
                PendingLine::credit($ar, Money::fromRupees($amount))->forParty($customer),
            ], (string) Str::uuid(), $this->owner);
        }

        $statement = $this->queries->partyStatement($customer, now()->subDays(10), now());

        expect($statement['opening']->toDecimal())->toBe('700.00')
            ->and(collect($statement['rows'])->map(fn ($r) => [$r['credit']->toDecimal(), $r['balance']->toDecimal()])->all())->toBe([['200.00', '500.00']])
            ->and($statement['closing']->toDecimal())->toBe('500.00');
    });
});

describe('archiving', function () {
    it('archives only parties with a zero balance', function () {
        $owing = makeParty($this->owner, 'Owing customer', [PartyRole::Customer], '50');
        $settled = makeParty($this->owner, 'Settled customer');

        expect(fn () => app(SetPartyArchived::class)->handle($this->owner, $owing, true))->toThrow(ValidationException::class);

        app(SetPartyArchived::class)->handle($this->owner, $settled, true);
        expect($settled->fresh()->is_active)->toBeFalse();
    });
});
