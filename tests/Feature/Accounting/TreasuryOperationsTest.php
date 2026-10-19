<?php

use App\Accounting\LedgerQueries;
use App\Actions\Accounting\RecordTransfer;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Accounting\SaveTreasuryAccount;
use App\Actions\Accounting\SetTreasuryAccountArchived;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

describe('cash and bank accounts', function () {
    it('creates a ledger account and posts the opening balance against "Opening balances"', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash in hand', '12,500.50');

        $entry = JournalEntry::query()->findOrFail($cash->opening_entry_id);
        expect($cash->ledgerAccount->code)->toBe('1001')
            ->and($entry->type)->toBe(JournalType::OpeningBalance)
            ->and($entry->entry_date->format('Y-m-d'))->toBe(now()->subDays(30)->format('Y-m-d'))
            ->and(balanceOf($cash))->toBe('12500.50')
            ->and(app(LedgerQueries::class)->balance(LedgerAccount::bySystemKey(LedgerAccount::OPENING_BALANCE_EQUITY))->toDecimal())->toBe('12500.50');
    });

    it('posts nothing for a zero opening balance', function () {
        $cash = makeTreasuryAccount($this->owner, 'Empty box', '0');

        expect($cash->opening_entry_id)->toBeNull()
            ->and(JournalEntry::query()->count())->toBe(0);
    });

    it('records a credit card debt as money owed (a liability)', function () {
        $card = makeTreasuryAccount($this->owner, 'Alfalah card', '30000', TreasuryKind::CreditCard);

        expect($card->ledgerAccount->code)->toBe('2001')
            ->and($card->ledgerAccount->type->value)->toBe('liability')
            ->and(balanceOf($card))->toBe('30000.00');
    });

    it('refuses a negative opening balance unless overdraft is allowed', function () {
        expect(fn () => makeTreasuryAccount($this->owner, 'Bank', '-5000', TreasuryKind::Bank))->toThrow(ValidationException::class);

        $bank = makeTreasuryAccount($this->owner, 'Bank OD', '-5000', TreasuryKind::Bank, ['allow_negative' => true]);
        expect(balanceOf($bank))->toBe('-5000.00');
    });

    it('validates account details', function (array $input, string $field) {
        makeTreasuryAccount($this->owner, 'Taken name');

        try {
            makeTreasuryAccount($this->owner, $input['name'] ?? 'New', $input['opening_balance'] ?? '0', TreasuryKind::Cash, $input);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    })->with([
        'duplicate name' => [['name' => 'taken name'], 'name'],
        'empty name' => [['name' => ''], 'name'],
        'bad amount' => [['opening_balance' => '12.345'], 'opening_balance'],
        'future opening date' => [fn () => ['opening_date' => now()->addDay()->format('Y-m-d')], 'opening_date'],
        'bad date' => [['opening_date' => '31-12-2025'], 'opening_date'],
        'unknown kind' => [['kind' => 'gold'], 'kind'],
    ]);

    it('changes the opening balance by reversing the old opening entry on its own date', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '10000');
        $oldEntryId = $cash->opening_entry_id;

        $updated = app(SaveTreasuryAccount::class)->handle($this->owner, $cash, [
            'name' => 'Cash', 'name_ur' => '', 'account_number' => '', 'allow_negative' => false,
            'opening_balance' => '12000', 'opening_date' => $cash->opening_date->format('Y-m-d'),
        ], (string) Str::uuid());

        $old = JournalEntry::query()->findOrFail($oldEntryId);
        $reversal = JournalEntry::query()->where('reversal_of_id', $oldEntryId)->sole();

        expect(balanceOf($updated))->toBe('12000.00')
            ->and($updated->opening_entry_id)->not->toBe($oldEntryId)
            ->and($old->isReversed())->toBeTrue()
            ->and($reversal->entry_date->format('Y-m-d'))->toBe($old->entry_date->format('Y-m-d'))
            ->and(app(LedgerQueries::class)->balance(LedgerAccount::bySystemKey(LedgerAccount::OPENING_BALANCE_EQUITY))->toDecimal())->toBe('12000.00');
    });

    it('does not move the opening date after existing transactions', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '10000');
        $bank = makeTreasuryAccount($this->owner, 'Bank', '10000', TreasuryKind::Bank);
        transferMoney($this->owner, $bank, $cash, '100', date: now()->subDays(10)->format('Y-m-d'));

        app(SaveTreasuryAccount::class)->handle($this->owner, $cash, [
            'name' => 'Cash', 'name_ur' => '', 'account_number' => '', 'allow_negative' => false,
            'opening_balance' => '10000', 'opening_date' => now()->subDays(5)->format('Y-m-d'),
        ], (string) Str::uuid());
    })->throws(ValidationException::class);

    it('archives only accounts with a zero balance', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '100');
        $empty = makeTreasuryAccount($this->owner, 'Empty', '0');

        expect(fn () => app(SetTreasuryAccountArchived::class)->handle($this->owner, $cash, true))->toThrow(ValidationException::class);

        app(SetTreasuryAccountArchived::class)->handle($this->owner, $empty, true);
        expect($empty->fresh()->is_active)->toBeFalse();
    });
});

describe('transfers', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $this->bank = makeTreasuryAccount($this->owner, 'Bank', '5000', TreasuryKind::Bank);
    });

    it('refuses to take more money than an account holds', function () {
        try {
            transferMoney($this->owner, $this->cash, $this->bank, '1000.01');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('amount');
        }

        expect(balanceOf($this->cash))->toBe('1000.00')
            ->and(JournalEntry::query()->where('type', 'transfer')->count())->toBe(0)
            ->and(DB::table('number_sequences')->where('prefix', 'TR')->value('last_number'))->toBeNull();
    });

    it('allows exactly the full balance', function () {
        transferMoney($this->owner, $this->cash, $this->bank, '1000');

        expect(balanceOf($this->cash))->toBe('0.00')->and(balanceOf($this->bank))->toBe('6000.00');
    });

    it('lets an overdraft account go below zero', function () {
        $od = makeTreasuryAccount($this->owner, 'Bank OD', '0', TreasuryKind::Bank, ['allow_negative' => true]);
        transferMoney($this->owner, $od, $this->cash, '700');

        expect(balanceOf($od))->toBe('-700.00');
    });

    it('validates the transfer form', function (array $input, string $field) {
        $base = ['from_account_id' => $this->bank->id, 'to_account_id' => $this->cash->id, 'amount' => '100', 'entry_date' => now()->format('Y-m-d'), 'memo' => ''];
        $input = array_map(fn ($value) => $value === 'FROM' ? $this->bank->id : $value, $input);

        try {
            app(RecordTransfer::class)->handle($this->owner, array_merge($base, $input), (string) Str::uuid());
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    })->with([
        'same account' => [['to_account_id' => 'FROM'], 'to_account_id'],
        'zero amount' => [['amount' => '0'], 'amount'],
        'negative amount' => [['amount' => '-5'], 'amount'],
        'text amount' => [['amount' => 'five'], 'amount'],
        'future date' => [fn () => ['entry_date' => now()->addDay()->format('Y-m-d')], 'entry_date'],
        'before opening date' => [fn () => ['entry_date' => now()->subDays(60)->format('Y-m-d')], 'entry_date'],
        'unknown account' => [['from_account_id' => 999999], 'from_account_id'],
    ]);

    it('refuses archived accounts', function () {
        $empty = makeTreasuryAccount($this->owner, 'Old box', '0');
        app(SetTreasuryAccountArchived::class)->handle($this->owner, $empty, true);

        transferMoney($this->owner, $this->bank, $empty, '10');
    })->throws(ValidationException::class);
});

describe('owner capital', function () {
    beforeEach(fn () => $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000'));

    it('records capital in and withdrawals', function () {
        $in = recordCapital($this->owner, 'in', $this->cash, '5000');
        $out = recordCapital($this->owner, 'out', $this->cash, '2500');

        $queries = app(LedgerQueries::class);
        expect(balanceOf($this->cash))->toBe('3500.00')
            ->and($in->type)->toBe(JournalType::CapitalIn)
            ->and($out->type)->toBe(JournalType::CapitalOut)
            ->and($queries->balance(LedgerAccount::bySystemKey(LedgerAccount::OWNER_CAPITAL))->toDecimal())->toBe('5000.00')
            ->and($queries->balance(LedgerAccount::bySystemKey(LedgerAccount::OWNER_DRAWINGS))->toDecimal())->toBe('-2500.00');
    });

    it('cannot withdraw more than the account holds', function () {
        recordCapital($this->owner, 'out', $this->cash, '1000.01');
    })->throws(ValidationException::class);

    it('is for the owner only', function () {
        $manager = makeUser($this->kiln, Role::Manager);
        recordCapital($manager, 'in', $this->cash, '100');
    })->throws(AuthorizationException::class);
});

describe('reversing from the transactions screen', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        $this->bank = makeTreasuryAccount($this->owner, 'Bank', '0', TreasuryKind::Bank);
    });

    it('needs a reason', function () {
        $transfer = transferMoney($this->owner, $this->cash, $this->bank, '100');

        app(ReverseEntry::class)->handle($this->owner, $transfer, ['reason' => 'no'], (string) Str::uuid());
    })->throws(ValidationException::class);

    it('refuses a reversal that would make an account negative', function () {
        $transfer = transferMoney($this->owner, $this->cash, $this->bank, '600');
        transferMoney($this->owner, $this->bank, $this->cash, '600'); // the 600 already left the bank again

        app(ReverseEntry::class)->handle($this->owner, $transfer, ['reason' => 'Wrong account'], (string) Str::uuid());
    })->throws(ValidationException::class);

    it('does not reverse opening balances here', function () {
        $opening = JournalEntry::query()->findOrFail($this->cash->opening_entry_id);

        app(ReverseEntry::class)->handle($this->owner, $opening, ['reason' => 'Trying it'], (string) Str::uuid());
    })->throws(ValidationException::class);

    it('handles the same reverse request twice without error or double effect', function () {
        $transfer = transferMoney($this->owner, $this->cash, $this->bank, '100');
        $key = (string) Str::uuid();

        $first = app(ReverseEntry::class)->handle($this->owner, $transfer, ['reason' => 'Wrong amount'], $key);
        $second = app(ReverseEntry::class)->handle($this->owner, $transfer->fresh(), ['reason' => 'Wrong amount'], $key);

        expect($second->id)->toBe($first->id)->and(balanceOf($this->cash))->toBe('1000.00');
    });

    it('says clearly when an entry was already reversed', function () {
        $transfer = transferMoney($this->owner, $this->cash, $this->bank, '100');
        app(ReverseEntry::class)->handle($this->owner, $transfer, ['reason' => 'Wrong amount'], (string) Str::uuid());

        app(ReverseEntry::class)->handle($this->owner, $transfer->fresh(), ['reason' => 'Again please'], (string) Str::uuid());
    })->throws(ValidationException::class);
});
