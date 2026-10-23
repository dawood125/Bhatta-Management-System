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

/**
 * Raw bricks loaded into the kiln for firing (Kamyaar's work), entry LD-…. Never more than the
 * raw bricks available on that day and every later day.
 */
class RecordLoading
{
    use PostsProduction, ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  entry_date, quantity, notes
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionRecord);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Loading)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['notes']);

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'quantity' => ['required'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'quantity.required' => __('Enter how many bricks were loaded.'),
        ], [
            'entry_date' => __('date'),
        ])->validate();

        $quantity = $this->bricks($data['quantity'], 'quantity');
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();

        return $this->posting($idempotencyKey, ProductionEntryKind::Loading, function () use ($actor, $data, $date, $quantity, $idempotencyKey): ProductionEntry {
            $this->assertStageAllows(StageKind::Raw, null, $date, Quantity::zero()->subtract($quantity), 'quantity');

            $entry = $this->createEntry(ProductionEntryKind::Loading, $date, ['quantity' => $quantity, 'notes' => $data['notes']], $idempotencyKey, $actor);
            $this->move($entry, StageKind::Raw, StageKind::InFiring, null, $quantity, $actor);

            return $entry;
        });
    }
}
