<?php

namespace App\Actions\Production;

use App\Actions\Production\Concerns\PostsProduction;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\Product;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A brick stock correction with a reason (entry BA-…), by the owner or manager: bricks found
 * when counting, or broken / lost. On saleable stock it names the grade.
 */
class RecordBrickAdjustment
{
    use PostsProduction, ValidatesSettings;

    public const ADD = 'add';

    public const REMOVE = 'remove';

    /**
     * @param  array<string, mixed>  $input  entry_date, stage, product_id (saleable only), direction, quantity, reason
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionAdjust);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Adjustment)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['product_id']);
        $isSaleable = ($input['stage'] ?? null) === StageKind::Saleable->value;
        if (! $isSaleable) {
            $input['product_id'] = null;
        }

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'stage' => ['required', Rule::enum(StageKind::class)],
            'product_id' => $isSaleable
                ? ['required', 'integer', Rule::exists('products', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)]
                : ['nullable'],
            'direction' => ['required', Rule::in([self::ADD, self::REMOVE])],
            'quantity' => ['required'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'product_id.required' => __('Choose the grade.'),
            'product_id.exists' => __('Choose a grade or by-product.'),
            'reason.required' => __('Say why, e.g. counted more, broken in rain.'),
        ], [
            'entry_date' => __('date'),
            'stage' => __('stage'),
        ])->validate();

        $stage = StageKind::from($data['stage']);
        $product = $isSaleable ? Product::query()->with('unit')->findOrFail((int) $data['product_id']) : null;
        $decimals = $product !== null ? $product->unit->decimals : 0;
        $quantity = $this->quantity($data['quantity'], $decimals, 'quantity');
        $adding = $data['direction'] === self::ADD;
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();

        return $this->posting($idempotencyKey, ProductionEntryKind::Adjustment, function () use ($actor, $data, $date, $stage, $product, $decimals, $quantity, $adding, $idempotencyKey): ProductionEntry {
            if (! $adding) {
                $this->assertStageAllows($stage, $product?->id, $date, Quantity::zero()->subtract($quantity), 'quantity', $decimals);
            }

            $entry = $this->createEntry(ProductionEntryKind::Adjustment, $date, ['quantity' => $quantity, 'notes' => $data['reason']], $idempotencyKey, $actor);
            $this->move($entry, $adding ? null : $stage, $adding ? $stage : null, $product?->id, $quantity, $actor);

            return $entry;
        });
    }
}
