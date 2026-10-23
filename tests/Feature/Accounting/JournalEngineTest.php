<?php

use App\Accounting\DocumentNumbers;
use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->poster = app(JournalPoster::class);
    $this->capital = LedgerAccount::bySystemKey(LedgerAccount::OWNER_CAPITAL);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash')->ledgerAccount;
});

function post(array $lines, ?string $key = null, ?CarbonInterface $date = null): JournalEntry
{
    return app(JournalPoster::class)->post(JournalType::CapitalIn, $date ?? now(), $lines, $key ?? (string) Str::uuid(), auth()->user());
}

describe('posting rules', function () {
    it('posts a balanced entry with numbered lines', function () {
        $entry = post([PendingLine::debit($this->cash, Money::fromRupees('500')), PendingLine::credit($this->capital, Money::fromRupees('500'))]);

        expect($entry->total->toDecimal())->toBe('500.00')
            ->and($entry->kiln_id)->toBe($this->kiln->id)
            ->and($entry->created_by)->toBe($this->owner->id)
            ->and($entry->lines()->pluck('line_no')->all())->toBe([1, 2]);
    });

    it('refuses unbalanced entries', function () {
        post([PendingLine::debit($this->cash, Money::fromRupees('500')), PendingLine::credit($this->capital, Money::fromRupees('499.99'))]);
    })->throws(LedgerIntegrityException::class, 'Unbalanced');

    it('refuses entries with fewer than two lines', function () {
        post([PendingLine::debit($this->cash, Money::fromRupees('500'))]);
    })->throws(LedgerIntegrityException::class);

    it('refuses zero and negative amounts', function (string $amount) {
        post([PendingLine::debit($this->cash, Money::fromRupees($amount)), PendingLine::credit($this->capital, Money::fromRupees($amount))]);
    })->with(['0', '-100'])->throws(LedgerIntegrityException::class);

    it('refuses accounts of another kiln', function () {
        $other = makeSeededKiln();
        $foreign = app(CurrentKiln::class)->run($other, fn () => LedgerAccount::bySystemKey(LedgerAccount::OWNER_CAPITAL));
        app(CurrentKiln::class)->set($this->kiln);

        post([PendingLine::debit($this->cash, Money::fromRupees('5')), PendingLine::credit($foreign, Money::fromRupees('5'))]);
    })->throws(LedgerIntegrityException::class);

    it('refuses future dates', function () {
        post([PendingLine::debit($this->cash, Money::fromRupees('5')), PendingLine::credit($this->capital, Money::fromRupees('5'))], date: now()->addDay());
    })->throws(LedgerIntegrityException::class);

    it('rolls back completely when a line fails to save', function () {
        $before = JournalEntry::query()->count();

        try {
            DB::transaction(function () {
                post([PendingLine::debit($this->cash, Money::fromRupees('5')), PendingLine::credit($this->capital, Money::fromRupees('5'))]);
                throw new RuntimeException('Simulated failure after posting');
            });
        } catch (RuntimeException) {
        }

        expect(JournalEntry::query()->count())->toBe($before)
            ->and(DB::table('number_sequences')->where('prefix', 'CP')->value('last_number'))->toBeNull();
    });

    it('rejects a reused key for a different kind of entry', function () {
        $key = (string) Str::uuid();
        post([PendingLine::debit($this->cash, Money::fromRupees('5')), PendingLine::credit($this->capital, Money::fromRupees('5'))], $key);

        app(JournalPoster::class)->post(JournalType::Transfer, now(), [
            PendingLine::debit($this->cash, Money::fromRupees('5')), PendingLine::credit($this->capital, Money::fromRupees('5')),
        ], $key, $this->owner);
    })->throws(LedgerIntegrityException::class);
});

describe('immutability', function () {
    beforeEach(function () {
        $this->entry = post([PendingLine::debit($this->cash, Money::fromRupees('500')), PendingLine::credit($this->capital, Money::fromRupees('500'))]);
    });

    it('never lets a posted entry be edited', function () {
        $this->entry->memo = 'changed';
        $this->entry->save();
    })->throws(LedgerIntegrityException::class);

    it('never lets a posted entry be deleted', function () {
        $this->entry->delete();
    })->throws(LedgerIntegrityException::class);

    it('never lets a line be edited or deleted', function () {
        $line = $this->entry->lines()->first();

        expect(fn () => $line->update(['memo' => 'x']))->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $line->delete())->toThrow(LedgerIntegrityException::class)
            ->and(JournalLine::query()->where('journal_entry_id', $this->entry->id)->count())->toBe(2);
    });

    it('lets the database refuse a two-sided line even if the application were bypassed', function () {
        DB::table('journal_lines')->insert([
            'kiln_id' => $this->kiln->id, 'journal_entry_id' => $this->entry->id, 'ledger_account_id' => $this->cash->id,
            'line_no' => 9, 'debit' => 100, 'credit' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    })->throws(QueryException::class);
});

describe('document numbers', function () {
    it('counts up without gaps per prefix', function () {
        $numbers = collect(range(1, 3))->map(fn () => post([
            PendingLine::debit($this->cash, Money::fromRupees('1')), PendingLine::credit($this->capital, Money::fromRupees('1')),
        ])->number);

        $year = now()->year;
        expect($numbers->all())->toBe(["CP-{$year}-0001", "CP-{$year}-0002", "CP-{$year}-0003"]);
    });

    it('starts again at 1 each year and keeps each kiln separate', function () {
        $numbers = app(DocumentNumbers::class);

        DB::transaction(function () use ($numbers) {
            expect($numbers->next('XX', now()->setDate(2025, 6, 1)))->toBe('XX-2025-0001')
                ->and($numbers->next('XX', now()->setDate(2025, 6, 2)))->toBe('XX-2025-0002')
                ->and($numbers->next('XX', now()->setDate(2026, 1, 1)))->toBe('XX-2026-0001');
        });

        $other = makeKiln();
        app(CurrentKiln::class)->run($other, fn () => DB::transaction(fn () => expect($numbers->next('XX', now()->setDate(2025, 6, 1)))->toBe('XX-2025-0001')));
    });
    // Note: "must be called inside a transaction" cannot be tested here, because every test
    // already runs inside a database transaction (RefreshDatabase).
});

describe('reversals', function () {
    beforeEach(function () {
        $this->entry = post([PendingLine::debit($this->cash, Money::fromRupees('500')), PendingLine::credit($this->capital, Money::fromRupees('500'))]);
    });

    it('mirrors every line and marks the original reversed', function () {
        $reversal = $this->poster->reverse($this->entry, now(), 'Typing mistake', (string) Str::uuid(), $this->owner);

        $original = $this->entry->lines()->get()->map(fn ($l) => [$l->ledger_account_id, $l->debit->paisa, $l->credit->paisa]);
        $mirror = $reversal->lines()->get()->map(fn ($l) => [$l->ledger_account_id, $l->credit->paisa, $l->debit->paisa]);

        expect($mirror->all())->toBe($original->all())
            ->and($reversal->type)->toBe(JournalType::Reversal)
            ->and($this->entry->fresh()->isReversed())->toBeTrue();
    });

    it('cannot reverse the same entry twice', function () {
        $this->poster->reverse($this->entry, now(), 'First', (string) Str::uuid(), $this->owner);
        $this->poster->reverse($this->entry, now(), 'Second', (string) Str::uuid(), $this->owner);
    })->throws(LedgerIntegrityException::class);

    it('cannot reverse a reversal', function () {
        $reversal = $this->poster->reverse($this->entry, now(), 'First', (string) Str::uuid(), $this->owner);
        $this->poster->reverse($reversal, now(), 'Again', (string) Str::uuid(), $this->owner);
    })->throws(LedgerIntegrityException::class);

    it('cannot be dated before the original', function () {
        $old = post([PendingLine::debit($this->cash, Money::fromRupees('5')), PendingLine::credit($this->capital, Money::fromRupees('5'))], date: now()->subDays(3));
        $this->poster->reverse($old, now()->subDays(4), 'Too early', (string) Str::uuid(), $this->owner);
    })->throws(LedgerIntegrityException::class);
});
