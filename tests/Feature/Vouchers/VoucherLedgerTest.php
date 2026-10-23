<?php

use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerQueries;
use App\Accounting\LedgerVerifier;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Accounting\SetTreasuryAccountArchived;
use App\Actions\Parties\SaveParty;
use App\Actions\Parties\SetPartyArchived;
use App\Actions\Vouchers\RecordVoucher;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->queries = app(LedgerQueries::class);
});

/** The field names of a validation failure. */
function voucherErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    test()->fail('Expected a validation error.');
}

describe('acceptance example (plan M4)', function () {
    it('pays a supplier 30,000 and a driver 5,000 from cash 70,000 in one balanced voucher', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '70,000');
        $supplier = makeParty($this->owner, 'Supplier A', [PartyRole::Supplier], '40,000', SaveParty::WE_OWE_THEM);
        $driver = makeParty($this->owner, 'Driver B', [PartyRole::Driver], '5,000', SaveParty::WE_OWE_THEM);

        $voucher = recordVoucher($this->owner, JournalType::Payment, $cash, [[$supplier, '30,000'], [$driver, '5,000']]);

        $debits = $voucher->lines->reduce(fn ($sum, $line) => $sum + $line->debit->paisa, 0);
        $credits = $voucher->lines->reduce(fn ($sum, $line) => $sum + $line->credit->paisa, 0);

        expect(balanceOf($cash))->toBe('35000.00')
            ->and($supplier->balance()->toDecimal())->toBe('-10000.00') // was 40,000 payable, fell by 30,000
            ->and($driver->balance()->toDecimal())->toBe('0.00')        // was 5,000 payable, fell by 5,000
            ->and($debits)->toBe($credits)
            ->and($voucher->total->toDecimal())->toBe('35000.00')
            ->and($voucher->type)->toBe(JournalType::Payment)
            ->and($voucher->number)->toBe('PV-'.now()->year.'-0001')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('payments and receipts', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '50000');
        $this->bank = makeTreasuryAccount($this->owner, 'Bank', '100000', TreasuryKind::Bank);
        $this->customer = makeParty($this->owner, 'Customer', [PartyRole::Customer], '10000');
        $this->supplier = makeParty($this->owner, 'Supplier', [PartyRole::Supplier], '20000', SaveParty::WE_OWE_THEM);
    });

    it('receives money from a customer into the bank', function () {
        $receipt = recordVoucher($this->owner, JournalType::Receipt, $this->bank, [[$this->customer, '4,000', 'Part payment']]);

        $partyLine = $receipt->lines->firstWhere('line_no', 2);

        expect(balanceOf($this->bank))->toBe('104000.00')
            ->and($this->customer->balance()->toDecimal())->toBe('6000.00')
            ->and($receipt->number)->toBe('RCV-'.now()->year.'-0001')
            ->and($receipt->lines->firstWhere('line_no', 1)->debit->toDecimal())->toBe('4000.00')
            ->and($partyLine->credit->toDecimal())->toBe('4000.00')
            ->and($partyLine->memo)->toBe('Part payment')
            ->and($partyLine->ledger_account_id)->toBe(LedgerAccount::bySystemKey(LedgerAccount::RECEIVABLES)->id);
    });

    it('posts each party to its own control account: customers to receivables, others to payables', function () {
        // A refund to a customer stays in receivables; a payment to a supplier goes to payables.
        $payment = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->customer, '500'], [$this->supplier, '700']]);

        $accountOf = fn ($party) => $payment->lines->firstWhere('party_id', $party->id)->ledger_account_id;

        expect($accountOf($this->customer))->toBe(LedgerAccount::bySystemKey(LedgerAccount::RECEIVABLES)->id)
            ->and($accountOf($this->supplier))->toBe(LedgerAccount::bySystemKey(LedgerAccount::PAYABLES)->id)
            ->and($this->customer->balance()->toDecimal())->toBe('10500.00')
            ->and($this->supplier->balance()->toDecimal())->toBe('-19300.00');
    });

    it('can move money to and from the kiln\'s own accounts on the same voucher', function () {
        // Paid from bank: 30,000 into cash in hand and 5,000 to the supplier.
        recordVoucher($this->owner, JournalType::Payment, $this->bank, [[$this->cash, '30000'], [$this->supplier, '5000']]);
        // Received into cash: 2,000 handed over from the bank account.
        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->bank, '2000']]);

        expect(balanceOf($this->bank))->toBe('63000.00')
            ->and(balanceOf($this->cash))->toBe('82000.00')
            ->and($this->supplier->balance()->toDecimal())->toBe('-15000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('keeps the line remarks and the voucher note', function () {
        $voucher = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '100', 'Coal, 2 trolleys']], ['memo' => 'Weekly settlement']);

        expect($voucher->memo)->toBe('Weekly settlement')
            ->and($voucher->lines->firstWhere('line_no', 2)->memo)->toBe('Coal, 2 trolleys');
    });

    it('numbers payments and receipts separately', function () {
        $p1 = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '10']]);
        $r1 = recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '10']]);
        $p2 = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '10']]);

        $year = now()->year;
        expect([$p1->number, $r1->number, $p2->number])->toBe(["PV-{$year}-0001", "RCV-{$year}-0001", "PV-{$year}-0002"]);
    });

    it('refuses to pay more than the account holds and records nothing', function () {
        $before = JournalEntry::query()->count();

        expect(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '30000'], [$this->customer, '20000.01']])))
            ->toBe(['account_id'])
            ->and(JournalEntry::query()->count())->toBe($before)
            ->and(balanceOf($this->cash))->toBe('50000.00');
    });

    it('refuses to take more from one of our accounts than it holds', function () {
        expect(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '1'], [$this->bank, '100000.01']])))
            ->toBe(['lines.1.account_id']);
    });

    it('lets an overdraft account go below zero', function () {
        $od = makeTreasuryAccount($this->owner, 'Running finance', '0', TreasuryKind::Bank, ['allow_negative' => true]);

        recordVoucher($this->owner, JournalType::Payment, $od, [[$this->supplier, '2500']]);

        expect(balanceOf($od))->toBe('-2500.00');
    });

    it('raises what is owed on a credit card when paying with it', function () {
        $card = makeTreasuryAccount($this->owner, 'Company card', '0', TreasuryKind::CreditCard);

        recordVoucher($this->owner, JournalType::Payment, $card, [[$this->supplier, '1200']]);

        expect(balanceOf($card))->toBe('1200.00')->and($this->supplier->balance()->toDecimal())->toBe('-18800.00');
    });

    it('returns the first voucher when the same form is sent twice', function () {
        $key = (string) Str::uuid();

        $first = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '1000']], key: $key);
        $second = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '1000']], key: $key);

        expect($second->id)->toBe($first->id)
            ->and(JournalEntry::query()->where('type', JournalType::Payment)->count())->toBe(1)
            ->and(balanceOf($this->cash))->toBe('49000.00');
    });

    it('refuses a key that was used for a different kind of entry', function () {
        $key = (string) Str::uuid();
        recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '1000']], key: $key);

        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '1000']], key: $key);
    })->throws(LedgerIntegrityException::class);

    it('is reversed with a reason, restoring every balance', function () {
        $voucher = recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '3000'], [$this->bank, '1000']]);

        $reversal = app(ReverseEntry::class)->handle($this->owner, $voucher, ['reason' => 'Entered twice'], (string) Str::uuid());

        $statement = $this->queries->partyStatement($this->supplier, now()->subYear(), now());

        expect($voucher->fresh()->isReversed())->toBeTrue()
            ->and($reversal->type)->toBe(JournalType::Reversal)
            ->and(balanceOf($this->cash))->toBe('50000.00')
            ->and(balanceOf($this->bank))->toBe('100000.00')
            ->and($this->supplier->balance()->toDecimal())->toBe('-20000.00')
            ->and(count($statement['rows']))->toBe(3) // opening, payment, reversal
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('validation', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '50000');
        $this->bank = makeTreasuryAccount($this->owner, 'Bank', '0', TreasuryKind::Bank);
        $this->supplier = makeParty($this->owner, 'Supplier', [PartyRole::Supplier]);
    });

    it('checks every field', function (array $input, string $field) {
        $base = [
            'account_id' => (string) $this->cash->id,
            'entry_date' => now()->format('Y-m-d'),
            'memo' => '',
            'lines' => [voucherLine($this->supplier, '100')],
        ];

        expect(voucherErrors(fn () => app(RecordVoucher::class)->handle($this->owner, JournalType::Payment, array_replace_recursive($base, $input), (string) Str::uuid())))
            ->toContain($field);
    })->with([
        'no account' => [fn () => ['account_id' => ''], 'account_id'],
        'unknown account' => [fn () => ['account_id' => '999999'], 'account_id'],
        'future date' => [fn () => ['entry_date' => now()->addDay()->format('Y-m-d')], 'entry_date'],
        'bad date' => [fn () => ['entry_date' => '19-10-2026'], 'entry_date'],
        'zero amount' => [fn () => ['lines' => [['amount' => '0']]], 'lines.0.amount'],
        'negative amount' => [fn () => ['lines' => [['amount' => '-5']]], 'lines.0.amount'],
        'text amount' => [fn () => ['lines' => [['amount' => 'five hundred']]], 'lines.0.amount'],
        'three decimals' => [fn () => ['lines' => [['amount' => '10.005']]], 'lines.0.amount'],
        'unknown line type' => [fn () => ['lines' => [['type' => 'expense']]], 'lines.0.type'],
        'party missing' => [fn () => ['lines' => [['party_id' => '']]], 'lines.0.party_id'],
        'unknown party' => [fn () => ['lines' => [['party_id' => '999999']]], 'lines.0.party_id'],
        'account line to itself' => [fn () => ['lines' => [voucherLine($this->cash, '10')]], 'lines.0.account_id'],
        'account line missing' => [fn () => ['lines' => [['type' => 'account', 'party_id' => '']]], 'lines.0.account_id'],
    ]);

    it('needs at least one line and at most 30', function () {
        $base = ['account_id' => (string) $this->cash->id, 'entry_date' => now()->format('Y-m-d'), 'memo' => ''];
        $handle = fn (array $lines) => app(RecordVoucher::class)->handle($this->owner, JournalType::Payment, $base + ['lines' => $lines], (string) Str::uuid());

        expect(voucherErrors(fn () => $handle([])))->toBe(['lines'])
            ->and(voucherErrors(fn () => $handle(array_fill(0, 31, voucherLine($this->supplier, '1')))))->toBe(['lines']);

        expect($handle(array_fill(0, 30, voucherLine($this->supplier, '1')))->lines)->toHaveCount(31);
    });

    it('refuses archived parties and accounts', function () {
        $settled = makeParty($this->owner, 'Old supplier', [PartyRole::Supplier]);
        app(SetPartyArchived::class)->handle($this->owner, $settled, true);
        app(SetTreasuryAccountArchived::class)->handle($this->owner, $this->bank, true);

        expect(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$settled, '10']])))->toBe(['lines.0.party_id'])
            ->and(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $this->bank, [[$this->supplier, '10']])))->toBe(['account_id']);
    });

    it('refuses a party or account of another kiln', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        [$foreignParty, $foreignCash] = app(CurrentKiln::class)->run($other, fn () => [
            makeParty($otherOwner, 'Their supplier', [PartyRole::Supplier]),
            makeTreasuryAccount($otherOwner, 'Their cash', '1000'),
        ]);
        app(CurrentKiln::class)->set($this->kiln);

        expect(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$foreignParty, '10']])))->toBe(['lines.0.party_id'])
            ->and(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $foreignCash, [[$this->supplier, '10']])))->toBe(['account_id']);
    });

    it('refuses dates before the opening date of an account or a party', function () {
        $newParty = makeParty($this->owner, 'Opened today', [PartyRole::Supplier], extra: ['opening_date' => now()->format('Y-m-d')]);

        expect(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$this->supplier, '10']], ['entry_date' => now()->subDays(31)->format('Y-m-d')])))->toBe(['entry_date'])
            ->and(voucherErrors(fn () => recordVoucher($this->owner, JournalType::Payment, $this->cash, [[$newParty, '10']], ['entry_date' => now()->subDay()->format('Y-m-d')])))->toBe(['entry_date']);
    });

    it('is refused for roles without permission, even when calling the action directly', function (Role $role) {
        recordVoucher(makeUser($this->kiln, $role), JournalType::Payment, $this->cash, [[$this->supplier, '10']]);
    })->throws(AuthorizationException::class)->with([Role::Viewer, Role::DataEntry]);

    it('lets the munshi record vouchers but not reverse them', function () {
        $munshi = makeUser($this->kiln, Role::Munshi);
        $voucher = recordVoucher($munshi, JournalType::Payment, $this->cash, [[$this->supplier, '10']]);

        expect($voucher->created_by)->toBe($munshi->id)
            ->and(fn () => app(ReverseEntry::class)->handle($munshi, $voucher, ['reason' => 'Mistake made'], (string) Str::uuid()))
            ->toThrow(AuthorizationException::class);
    });
});

describe('verifier', function () {
    it('catches a voucher whose first line is not the cash/bank account', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $supplier = makeParty($this->owner, 'Supplier', [PartyRole::Supplier]);
        $voucher = recordVoucher($this->owner, JournalType::Payment, $cash, [[$supplier, '100']]);
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        // Swap the line numbers behind the application's back.
        DB::table('journal_lines')->where('journal_entry_id', $voucher->id)->where('line_no', 1)->update(['line_no' => 99]);
        DB::table('journal_lines')->where('journal_entry_id', $voucher->id)->where('line_no', 2)->update(['line_no' => 1]);

        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain("Voucher {$voucher->number}");
    });
});
