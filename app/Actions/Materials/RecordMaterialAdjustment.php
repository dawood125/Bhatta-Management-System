<?php

namespace App\Actions\Materials;

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Materials\Concerns\GuardsStock;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\MaterialMovementType;
use App\Enums\Permission;
use App\Materials\MaterialStock;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MaterialAdjustment;
use App\Models\MaterialMovement;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Stock corrections of one material (entry SA-…), by the owner or manager:
 * - Opening stock: material already at the kiln when the system starts, with what it cost
 *   (debit Material stock, credit Opening balances).
 * - Shortage: less found when counting, or wasted; valued at average cost, with a reason
 *   (debit Material shortages, credit Material stock).
 */
class RecordMaterialAdjustment
{
    use GuardsStock, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly MaterialStock $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  kind, material_id, adjustment_date, quantity, value (opening only), reason
     */
    public function handle(User $actor, array $input, string $idempotencyKey): MaterialAdjustment
    {
        $this->authorizeSettings($actor, Permission::MaterialsAdjust);

        if (($existing = $this->findByKey($idempotencyKey)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['value', 'reason']);
        $isOpening = ($input['kind'] ?? null) === MaterialAdjustment::OPENING;

        $data = Validator::make($input, [
            'kind' => ['required', Rule::in([MaterialAdjustment::OPENING, MaterialAdjustment::SHORTAGE])],
            'material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)],
            'adjustment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'quantity' => ['required'],
            // An opening stock needs what it cost; a material without a cost is not tracked in stock.
            'value' => $isOpening ? ['required', new MoneyAmount] : ['nullable'],
            'reason' => $isOpening ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'min:3', 'max:255'],
        ], [
            'material_id.exists' => __('This material is archived or does not exist.'),
            'value.required' => __('Enter what this stock cost.'),
            'reason.required' => __('Say why stock is short, e.g. counted less, rain damage.'),
        ], [
            'adjustment_date' => __('date'),
            'material_id' => __('material'),
            'value' => __('value'),
        ])->validate();

        $date = Carbon::createFromFormat('Y-m-d', $data['adjustment_date'])->startOfDay();

        return DB::transaction(function () use ($actor, $data, $date, $isOpening, $idempotencyKey): MaterialAdjustment {
            $material = $this->lockMaterials([(int) $data['material_id']])->sole();

            try {
                $quantity = Quantity::fromInput((string) $data['quantity'], $material->unit->decimals);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['quantity' => $material->unit->decimals === 0
                    ? __('Enter a whole number.')
                    : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $material->unit->decimals])]);
            }

            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(['quantity' => __('The quantity must be more than zero.')]);
            }

            $stockAccount = LedgerAccount::bySystemKey(LedgerAccount::MATERIAL_STOCK);

            if ($isOpening) {
                $value = Money::fromRupees((string) $data['value']);
                $lines = [PendingLine::debit($stockAccount, $value), PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::OPENING_BALANCE_EQUITY), $value)];
            } else {
                $this->assertStockAllows($material, $date, Quantity::zero()->subtract($quantity), 'quantity');
                $value = $this->stock->valueOut($quantity, $this->stock->levels([$material->id])[$material->id]);

                if (! $value->isPositive()) {
                    throw ValidationException::withMessages(['quantity' => __('These quantities are worth less than one paisa. Enter a larger quantity.')]);
                }

                $lines = [PendingLine::debit(LedgerAccount::bySystemKey(LedgerAccount::MATERIAL_SHORTAGES), $value), PendingLine::credit($stockAccount, $value)];
            }

            $entry = $this->poster->post(JournalType::MaterialAdjustment, $date, $lines, $idempotencyKey, $actor, $data['reason']);

            if (! $entry->wasRecentlyCreated) {
                return MaterialAdjustment::query()->where('journal_entry_id', $entry->id)->firstOrFail();
            }

            $adjustment = MaterialAdjustment::query()->create([
                'journal_entry_id' => $entry->id,
                'adjustment_date' => $date->format('Y-m-d'),
                'kind' => $data['kind'],
                'material_id' => $material->id,
                'quantity' => $quantity,
                'value' => $value,
                'reason' => $data['reason'],
                'created_by' => $actor->id,
            ]);

            MaterialMovement::query()->create([
                'material_id' => $material->id,
                'movement_date' => $date->format('Y-m-d'),
                'type' => $isOpening ? MaterialMovementType::Opening : MaterialMovementType::Shortage,
                'quantity_in' => $isOpening ? $quantity : Quantity::zero(),
                'quantity_out' => $isOpening ? Quantity::zero() : $quantity,
                'value_in' => $isOpening ? $value : Money::zero(),
                'value_out' => $isOpening ? Money::zero() : $value,
                'journal_entry_id' => $entry->id,
                'material_adjustment_id' => $adjustment->id,
                'memo' => $data['reason'],
                'created_by' => $actor->id,
            ]);

            return $adjustment;
        });
    }

    private function findByKey(string $idempotencyKey): ?MaterialAdjustment
    {
        $entry = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->type !== JournalType::MaterialAdjustment) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return MaterialAdjustment::query()->where('journal_entry_id', $entry->id)->firstOrFail();
    }
}
