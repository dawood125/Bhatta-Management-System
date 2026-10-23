<?php

namespace App\Actions\Materials;

use App\Accounting\JournalPoster;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Materials\Concerns\GuardsStock;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\MaterialMovementType;
use App\Enums\Permission;
use App\Materials\MaterialStock;
use App\Models\JournalEntry;
use App\Models\MaterialMovement;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a material purchase, usage or stock adjustment: its journal entry is reversed (dated
 * today) and every stock movement gets a mirror movement, so quantity and value return exactly.
 *
 * Refused when the stock it brought in has been used since (stock would go below zero), or when
 * what is left in stock would no longer carry a sensible value.
 */
class ReverseMaterialEntry
{
    use GuardsBalances, GuardsStock, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly MaterialStock $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  reason
     */
    public function handle(User $actor, JournalEntry $entry, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::TransactionsReverse);
        $input = $this->clean($input, []);

        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ])->validate();

        if (! $entry->type->movesMaterialStock()) {
            throw ValidationException::withMessages(['reason' => __('This kind of entry cannot be reversed here.')]);
        }

        return DB::transaction(function () use ($actor, $entry, $data, $idempotencyKey): JournalEntry {
            $movements = MaterialMovement::query()->where('journal_entry_id', $entry->id)->whereNull('reversal_of_id')->orderBy('id')->get();
            $materials = $this->lockMaterials($movements->pluck('material_id')->map(fn ($id) => (int) $id)->all());
            $treasuryIds = TreasuryAccount::query()->whereIn('ledger_account_id', $entry->lines()->pluck('ledger_account_id'))->pluck('id')->all();
            $accounts = $this->lockTreasuryAccounts($treasuryIds);

            $fresh = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($fresh->isReversed()) {
                $existing = JournalEntry::query()->where('reversal_of_id', $fresh->id)->first();
                if ($existing !== null && $existing->idempotency_key === trim($idempotencyKey)) {
                    return $existing; // the same request arrived twice
                }

                throw ValidationException::withMessages(['reason' => __('This entry has already been reversed.')]);
            }

            // What the reversal does to each material, checked before anything is written.
            $levels = $this->stock->levels($materials->keys()->all());
            $today = now()->startOfDay();
            foreach ($materials as $material) {
                $own = $movements->where('material_id', $material->id);
                $quantity = Quantity::fromMilli($own->sum(fn (MaterialMovement $m) => $m->quantity_out->milli - $m->quantity_in->milli));
                $value = Money::fromPaisa($own->sum(fn (MaterialMovement $m) => $m->value_out->paisa - $m->value_in->paisa));

                if ($quantity->isNegative()) {
                    $this->assertStockAllows($material, $today, $quantity, 'reason');
                }

                $after = ['quantity' => $levels[$material->id]['quantity']->add($quantity), 'value' => $levels[$material->id]['value']->add($value)];
                if ($after['value']->isNegative() || ($after['quantity']->isZero() && ! $after['value']->isZero())) {
                    throw ValidationException::withMessages(['reason' => __(':material from this entry has been used since. Reverse that usage first, or record a shortage.', [
                        'material' => $material->displayName(),
                    ])]);
                }
            }

            $reversal = $this->poster->reverse($fresh, now(), $data['reason'], $idempotencyKey, $actor);

            foreach ($movements as $movement) {
                MaterialMovement::query()->create([
                    'material_id' => $movement->material_id,
                    'movement_date' => $today->format('Y-m-d'),
                    'type' => MaterialMovementType::Reversal,
                    'quantity_in' => $movement->quantity_out,
                    'quantity_out' => $movement->quantity_in,
                    'value_in' => $movement->value_out,
                    'value_out' => $movement->value_in,
                    'journal_entry_id' => $reversal->id,
                    'material_purchase_line_id' => $movement->material_purchase_line_id,
                    'material_usage_id' => $movement->material_usage_id,
                    'material_adjustment_id' => $movement->material_adjustment_id,
                    'reversal_of_id' => $movement->id,
                    'memo' => $data['reason'],
                    'created_by' => $actor->id,
                ]);
            }

            foreach ($accounts as $account) {
                $this->assertNotNegative($account, 'reason');
            }

            return $reversal;
        });
    }
}
