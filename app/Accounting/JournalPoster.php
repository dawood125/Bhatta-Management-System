<?php

namespace App\Accounting;

use App\Enums\JournalType;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way money enters the ledger.
 *
 * Every entry: belongs to the current kiln, has at least two lines, each line is a positive
 * debit or a positive credit, debits equal credits, and it gets a gap-free number. Posting the
 * same idempotency key twice returns the first entry instead of creating a second one, so a
 * double-click or a retried request can never record money twice.
 */
class JournalPoster
{
    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  list<PendingLine>  $lines
     */
    public function post(
        JournalType $type,
        CarbonInterface $date,
        array $lines,
        string $idempotencyKey,
        ?User $by,
        ?string $memo = null,
    ): JournalEntry {
        if ($type === JournalType::Reversal) {
            throw new LedgerIntegrityException('Reversals are posted with reverse().');
        }

        return $this->write($type, $date, $lines, $idempotencyKey, $by, $memo, null);
    }

    /**
     * Post the mirror image of an entry and mark the original as reversed.
     */
    public function reverse(JournalEntry $entry, CarbonInterface $date, string $reason, string $idempotencyKey, ?User $by): JournalEntry
    {
        if ($existing = $this->findByKey($idempotencyKey)) {
            return $this->assertSameIntent($existing, JournalType::Reversal);
        }

        return DB::transaction(function () use ($entry, $date, $reason, $idempotencyKey, $by): JournalEntry {
            /** @var JournalEntry $original */
            $original = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($original->type === JournalType::Reversal) {
                throw new LedgerIntegrityException('A reversal cannot itself be reversed; post a new entry instead.');
            }

            if ($original->isReversed()) {
                throw new LedgerIntegrityException('This entry has already been reversed.');
            }

            if ($date->format('Y-m-d') < $original->entry_date->format('Y-m-d')) {
                throw new LedgerIntegrityException('A reversal cannot be dated before the entry it reverses.');
            }

            $lines = [];
            foreach ($original->lines()->with(['account', 'party'])->get() as $line) {
                $mirror = $line->debit->isPositive()
                    ? PendingLine::credit($line->account, $line->debit, $line->memo)
                    : PendingLine::debit($line->account, $line->credit, $line->memo);
                $lines[] = $mirror->forParty($line->party);
            }

            $reversal = $this->write(JournalType::Reversal, $date, $lines, $idempotencyKey, $by, $reason, $original);

            $original->status = JournalEntry::STATUS_REVERSED;
            $original->save();

            return $reversal;
        });
    }

    /**
     * @param  list<PendingLine>  $lines
     */
    private function write(
        JournalType $type,
        CarbonInterface $date,
        array $lines,
        string $idempotencyKey,
        ?User $by,
        ?string $memo,
        ?JournalEntry $reversalOf,
    ): JournalEntry {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new LedgerIntegrityException('A valid idempotency key is required.');
        }

        if ($existing = $this->findByKey($idempotencyKey)) {
            return $this->assertSameIntent($existing, $type);
        }

        $total = $this->assertBalanced($lines);

        if ($date->format('Y-m-d') > now()->format('Y-m-d')) {
            throw new LedgerIntegrityException('Entries cannot be dated in the future.');
        }

        try {
            return DB::transaction(function () use ($type, $date, $lines, $idempotencyKey, $by, $memo, $reversalOf, $total): JournalEntry {
                $entry = JournalEntry::query()->create([
                    'number' => $this->numbers->next($type->prefix(), $date),
                    'entry_date' => $date->format('Y-m-d'),
                    'type' => $type,
                    'status' => JournalEntry::STATUS_POSTED,
                    'memo' => $memo,
                    'total' => $total,
                    'reversal_of_id' => $reversalOf?->id,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $by?->id,
                ]);

                foreach ($lines as $index => $line) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'ledger_account_id' => $line->account->id,
                        'party_id' => $line->party?->id,
                        'line_no' => $index + 1,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'memo' => $line->memo,
                    ]);
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced each other: the other one won, return its entry.
            if ($existing = $this->findByKey($idempotencyKey)) {
                return $this->assertSameIntent($existing, $type);
            }

            throw $e;
        }
    }

    /**
     * @param  list<PendingLine>  $lines
     */
    private function assertBalanced(array $lines): Money
    {
        if (count($lines) < 2) {
            throw new LedgerIntegrityException('An entry needs at least two lines.');
        }

        $kilnId = $this->currentKiln->id();
        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($lines as $line) {
            if (! $line->account->exists || $line->account->kiln_id !== $kilnId) {
                throw new LedgerIntegrityException('Every line must use an account of the current kiln.');
            }

            // Receivable/payable lines always name the party; other accounts never do.
            if ($line->account->requiresParty() && $line->party === null) {
                throw new LedgerIntegrityException("Lines on {$line->account->name} must name a party.");
            }

            if (! $line->account->requiresParty() && $line->party !== null) {
                throw new LedgerIntegrityException("Lines on {$line->account->name} cannot name a party.");
            }

            if ($line->party !== null && (! $line->party->exists || $line->party->kiln_id !== $kilnId)) {
                throw new LedgerIntegrityException('The party must belong to the current kiln.');
            }

            $isDebit = $line->debit->isPositive() && $line->credit->isZero();
            $isCredit = $line->credit->isPositive() && $line->debit->isZero();

            if (! $isDebit && ! $isCredit) {
                throw new LedgerIntegrityException('Each line must be a positive debit or a positive credit.');
            }

            $debits = $debits->add($line->debit);
            $credits = $credits->add($line->credit);
        }

        if (! $debits->equals($credits)) {
            throw new LedgerIntegrityException("Unbalanced entry: debits {$debits->format()} vs credits {$credits->format()}.");
        }

        return $debits;
    }

    private function findByKey(string $idempotencyKey): ?JournalEntry
    {
        return JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();
    }

    private function assertSameIntent(JournalEntry $existing, JournalType $type): JournalEntry
    {
        if ($existing->type !== $type) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return $existing;
    }
}
