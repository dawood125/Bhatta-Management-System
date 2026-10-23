<?php

/*
 * The acceptance examples agreed in docs/04-implementation-plan.md (M2), as executable tests.
 */

use App\Accounting\LedgerQueries;
use App\Actions\Accounting\ReverseEntry;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Models\JournalEntry;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);

    $this->cash = makeTreasuryAccount($this->owner, 'Cash in hand', '50,000');
    $this->bank = makeTreasuryAccount($this->owner, 'HBL', '200000', TreasuryKind::Bank);
});

it('example 1: opening balances Cash 50,000 and Bank 200,000 give a balanced trial balance', function () {
    expect(balanceOf($this->cash))->toBe('50000.00')
        ->and(balanceOf($this->bank))->toBe('200000.00');

    $tb = app(LedgerQueries::class)->trialBalance(now());

    expect($tb['total_debit']->toDecimal())->toBe('250000.00')
        ->and($tb['total_credit']->toDecimal())->toBe('250000.00');

    $credits = $tb['rows']->mapWithKeys(fn ($r) => [$r['account']->name => $r['credit']->toDecimal()]);
    expect($credits['Opening balances'])->toBe('250000.00');
});

it('example 2: transferring 20,000 from bank to cash gives Cash 70,000 and Bank 180,000, shown in both cash books', function () {
    $entry = transferMoney($this->owner, $this->bank, $this->cash, '20,000');

    expect(balanceOf($this->cash))->toBe('70000.00')
        ->and(balanceOf($this->bank))->toBe('180000.00')
        ->and($entry->number)->toBe('TR-'.now()->year.'-0001');

    $queries = app(LedgerQueries::class);
    $cashBook = $queries->statement($this->cash->ledgerAccount, now()->subYear(), now());
    $bankBook = $queries->statement($this->bank->ledgerAccount, now()->subYear(), now());

    expect(collect($cashBook['rows'])->pluck('line.entry.number'))->toContain($entry->number)
        ->and(collect($bankBook['rows'])->pluck('line.entry.number'))->toContain($entry->number)
        ->and($cashBook['closing']->toDecimal())->toBe('70000.00')
        ->and($bankBook['closing']->toDecimal())->toBe('180000.00');
});

it('example 3: reversing that transfer returns to 50,000 / 200,000 with both entries kept', function () {
    $transfer = transferMoney($this->owner, $this->bank, $this->cash, '20000');

    $reversal = app(ReverseEntry::class)->handle($this->owner, $transfer, ['reason' => 'Entered by mistake'], (string) Str::uuid());

    expect(balanceOf($this->cash))->toBe('50000.00')
        ->and(balanceOf($this->bank))->toBe('200000.00')
        ->and($transfer->fresh()->status)->toBe(JournalEntry::STATUS_REVERSED)
        ->and($reversal->reversal_of_id)->toBe($transfer->id)
        ->and($reversal->memo)->toBe('Entered by mistake')
        ->and(JournalEntry::query()->whereKey([$transfer->id, $reversal->id])->count())->toBe(2);

    $cashBook = app(LedgerQueries::class)->statement($this->cash->ledgerAccount, now()->subYear(), now());
    expect(collect($cashBook['rows'])->pluck('line.entry.number')->all())->toContain($transfer->number, $reversal->number);
});

it('example 4: two identical submissions with the same key record only one transfer', function () {
    $key = (string) Str::uuid();

    $first = transferMoney($this->owner, $this->bank, $this->cash, '20000', $key);
    $second = transferMoney($this->owner, $this->bank, $this->cash, '20000', $key);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::query()->where('type', 'transfer')->count())->toBe(1)
        ->and(balanceOf($this->cash))->toBe('70000.00');
});
