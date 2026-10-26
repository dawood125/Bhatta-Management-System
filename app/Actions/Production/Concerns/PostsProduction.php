<?php

namespace App\Actions\Production\Concerns;

use App\Accounting\BooksLock;
use App\Accounting\DocumentNumbers;
use App\Accounting\LedgerIntegrityException;
use App\Actions\Labour\RecordWorkEntry;
use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\BrickMovement;
use App\Models\ProductionEntry;
use App\Models\ProductionStage;
use App\Models\User;
use App\Models\Worker;
use App\Production\BrickStock;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared steps of the production actions: one-time keys, numbering, locking, writing brick
 * movements and the rule that no stage ever holds fewer than zero bricks on any day.
 */
trait PostsProduction
{
    /** The entry already saved with this one-time key, if the same form was sent twice. */
    protected function existingEntry(string $idempotencyKey, ProductionEntryKind $kind): ?ProductionEntry
    {
        $entry = ProductionEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry !== null && $entry->kind !== $kind) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return $entry;
    }

    /**
     * Run the posting inside a transaction with the kiln's stages locked, so two people can't
     * move the same bricks at once. A request that raced its own twin returns the twin's entry.
     *
     * @param  Closure(): ProductionEntry  $post
     */
    protected function posting(string $idempotencyKey, ProductionEntryKind $kind, Closure $post): ProductionEntry
    {
        try {
            return DB::transaction(function () use ($post): ProductionEntry {
                ProductionStage::query()->orderBy('id')->lockForUpdate()->get();

                return $post();
            });
        } catch (UniqueConstraintViolationException $e) {
            if (($existing = $this->existingEntry($idempotencyKey, $kind)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createEntry(ProductionEntryKind $kind, CarbonInterface $date, array $attributes, string $idempotencyKey, User $actor): ProductionEntry
    {
        app(BooksLock::class)->assertOpen($date, $actor);

        return ProductionEntry::query()->create($attributes + [
            'kind' => $kind,
            'number' => app(DocumentNumbers::class)->next($kind->prefix(), $date),
            'entry_date' => $date->format('Y-m-d'),
            'idempotency_key' => trim($idempotencyKey),
            'created_by' => $actor->id,
        ]);
    }

    protected function move(ProductionEntry $entry, ?StageKind $from, ?StageKind $to, ?int $productId, Quantity $quantity, User $actor): BrickMovement
    {
        return BrickMovement::query()->create([
            'production_entry_id' => $entry->id,
            'movement_date' => $entry->entry_date->format('Y-m-d'),
            'stage_from' => $from,
            'stage_to' => $to,
            'product_id' => $productId,
            'quantity' => $quantity,
            'created_by' => $actor->id,
        ]);
    }

    /** Refuse a change that would leave a stage below zero on the date or any later day. */
    protected function assertStageAllows(StageKind $stage, ?int $productId, CarbonInterface $date, Quantity $change, string $field, int $decimals = 0): void
    {
        $lowest = app(BrickStock::class)->lowestFrom($stage, $productId, $date, $change);

        if ($lowest->isNegative()) {
            throw ValidationException::withMessages([
                $field => __('Not enough in ":stage". This would leave :quantity.', [
                    'stage' => ProductionStage::query()->where('kind', $stage)->first()?->displayName() ?? $stage->defaultName(),
                    'quantity' => $lowest->format($decimals),
                ]),
            ]);
        }
    }

    /**
     * The worker named on a Pathai, loading or Nakaasi entry, if any. Whether their category is
     * paid for this work is checked when their wages are worked out.
     */
    protected function workerFrom(mixed $value): ?Worker
    {
        $id = trim((string) (is_scalar($value) ? $value : ''));
        if ($id === '') {
            return null;
        }

        $worker = ctype_digit($id) ? Worker::query()->with('category')->where('is_active', true)->find((int) $id) : null;
        if ($worker === null) {
            throw ValidationException::withMessages(['worker_id' => __('This worker is archived or does not exist.')]);
        }

        return $worker;
    }

    /** Wages for the bricks of this entry, when it names a worker. */
    protected function payWorker(ProductionEntry $entry, ?Worker $worker, Quantity $bricks, User $actor): void
    {
        if ($worker !== null && $bricks->isPositive()) {
            app(RecordWorkEntry::class)->forProduction($actor, $entry, $worker, $bricks);
        }
    }

    /** A whole number of bricks typed by a person; more than zero unless $allowZero. */
    protected function bricks(mixed $value, string $field, bool $allowZero = false): Quantity
    {
        return $this->quantity($value, 0, $field, $allowZero);
    }

    /** A quantity typed by a person in a unit with $decimals decimal places. */
    protected function quantity(mixed $value, int $decimals, string $field, bool $allowZero = false): Quantity
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        if ($text === '' && $allowZero) {
            return Quantity::zero();
        }

        try {
            $quantity = Quantity::fromInput($text, $decimals);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => $decimals === 0
                ? __('Enter a whole number.')
                : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $decimals])]);
        }

        if ($quantity->isNegative() || ($quantity->isZero() && ! $allowZero)) {
            throw ValidationException::withMessages([$field => __('The quantity must be more than zero.')]);
        }

        return $quantity;
    }
}
