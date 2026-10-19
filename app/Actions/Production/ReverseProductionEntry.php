<?php

namespace App\Actions\Production;

use App\Actions\Materials\ReverseMaterialEntry;
use App\Actions\Production\Concerns\PostsProduction;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\StageKind;
use App\Models\BrickMovement;
use App\Models\ProductionEntry;
use App\Models\ProductionStage;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a production entry by the owner or manager: every brick movement gets a mirror
 * movement dated today, the entry is marked reversed with the reason, and the fuel of a firing
 * entry goes back into material stock. Refused when the bricks have moved on since (e.g. made
 * bricks already loaded into the kiln): reverse the later entry first.
 */
class ReverseProductionEntry
{
    use PostsProduction, ValidatesSettings;

    public function __construct(private readonly ReverseMaterialEntry $reverseMaterial) {}

    /**
     * @param  array<string, mixed>  $input  reason
     */
    public function handle(User $actor, ProductionEntry $entry, array $input): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionAdjust);
        $input = $this->clean($input, []);

        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($actor, $entry, $data): ProductionEntry {
            ProductionStage::query()->orderBy('id')->lockForUpdate()->get();

            /** @var ProductionEntry $fresh */
            $fresh = ProductionEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($fresh->isReversed()) {
                throw ValidationException::withMessages(['reason' => __('This entry has already been reversed.')]);
            }

            $movements = $fresh->movements()->with('product.unit')->get();
            $today = now()->startOfDay();

            // What the reversal does to each stage (and grade), checked before anything is written.
            $changes = [];
            foreach ($movements as $movement) {
                foreach ([[$movement->stage_to, -1], [$movement->stage_from, 1]] as [$stage, $sign]) {
                    if ($stage === null) {
                        continue;
                    }
                    $productId = $stage === StageKind::Saleable ? $movement->product_id : null;
                    $key = $stage->value.':'.($productId ?? 0);
                    $changes[$key] ??= ['stage' => $stage, 'product_id' => $productId, 'milli' => 0, 'decimals' => $productId !== null ? $movement->product?->unit->decimals ?? 0 : 0];
                    $changes[$key]['milli'] += $sign * $movement->quantity->milli;
                }
            }

            foreach ($changes as $change) {
                if ($change['milli'] < 0) {
                    try {
                        $this->assertStageAllows($change['stage'], $change['product_id'], $today, Quantity::fromMilli($change['milli']), 'reason', $change['decimals']);
                    } catch (ValidationException) {
                        throw ValidationException::withMessages(['reason' => __('These bricks have moved on since this entry. Reverse the later entries first.')]);
                    }
                }
            }

            foreach ($movements as $movement) {
                BrickMovement::query()->create([
                    'production_entry_id' => $fresh->id,
                    'movement_date' => $today->format('Y-m-d'),
                    'stage_from' => $movement->stage_to,
                    'stage_to' => $movement->stage_from,
                    'product_id' => $movement->product_id,
                    'quantity' => $movement->quantity,
                    'reversal_of_id' => $movement->id,
                    'created_by' => $actor->id,
                ]);
            }

            // The fuel of a firing entry goes back into material stock too.
            if ($fresh->material_usage_id !== null) {
                $usage = $fresh->materialUsage()->with('entry')->firstOrFail();
                $this->reverseMaterial->reverse($actor, $usage->entry, ['reason' => $data['reason']], substr($fresh->idempotency_key, 0, 54).':fuel-rev');
            }

            $fresh->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $data['reason'],
            ])->save();

            return $fresh;
        });
    }
}
