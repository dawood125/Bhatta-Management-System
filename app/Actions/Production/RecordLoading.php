<?php

namespace App\Actions\Production;

use App\Actions\Production\Concerns\PostsProduction;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Raw bricks loaded into the kiln for firing (Kamyaar's work), entry LD-…. Never more than the
 * raw bricks available on that day and every later day.
 */
class RecordLoading
{
    use PostsProduction, ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  entry_date, quantity (raw bricks), refire (bricks going back in), notes
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionRecord);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Loading)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['quantity', 'refire', 'notes']);

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'quantity' => ['nullable'],
            'refire' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'entry_date' => __('date'),
        ])->validate();

        $quantity = $this->bricks($data['quantity'] ?? '', 'quantity', allowZero: true);
        $refire = $this->bricks($data['refire'] ?? '', 'refire', allowZero: true);
        if ($quantity->isZero() && $refire->isZero()) {
            throw ValidationException::withMessages(['quantity' => __('Enter how many bricks were loaded.')]);
        }

        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();
        $worker = $this->workerFrom($input['worker_id'] ?? null);

        return $this->posting($idempotencyKey, ProductionEntryKind::Loading, function () use ($actor, $data, $date, $quantity, $refire, $worker, $idempotencyKey): ProductionEntry {
            if ($quantity->isPositive()) {
                $this->assertStageAllows(StageKind::Raw, null, $date, Quantity::zero()->subtract($quantity), 'quantity');
            }
            if ($refire->isPositive()) {
                $this->assertStageAllows(StageKind::AwaitingRefire, null, $date, Quantity::zero()->subtract($refire), 'refire');
            }

            $entry = $this->createEntry(ProductionEntryKind::Loading, $date, ['quantity' => $quantity->add($refire), 'notes' => $data['notes'], 'worker_id' => $worker?->id], $idempotencyKey, $actor);
            if ($quantity->isPositive()) {
                $this->move($entry, StageKind::Raw, StageKind::InFiring, null, $quantity, $actor);
            }
            if ($refire->isPositive()) {
                $this->move($entry, StageKind::AwaitingRefire, StageKind::InFiring, null, $refire, $actor);
            }

            // The Kamyaar is paid once per brick: bricks going back in earn nothing again.
            $this->payWorker($entry, $worker, $quantity, $actor);

            return $entry;
        });
    }
}
