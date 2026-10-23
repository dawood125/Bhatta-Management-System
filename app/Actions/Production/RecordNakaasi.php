<?php

namespace App\Actions\Production;

use App\Actions\Production\Concerns\PostsProduction;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Enums\ProductKind;
use App\Enums\StageKind;
use App\Models\Product;
use App\Models\ProductionEntry;
use App\Models\Unit;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Nakaasi (entry NK-…): fired bricks unloaded and sorted. Each grade or brick by-product (Awal,
 * Doom, Khingar, Tooty, ...) moves from "fired, awaiting Nakaasi" into saleable stock; their total
 * is the number of bricks unloaded. By-products counted in another unit (e.g. Rora by the
 * trolley) are added to saleable stock alongside, without counting as bricks.
 */
class RecordNakaasi
{
    use PostsProduction, ValidatesSettings;

    public const MAX_LINES = 20;

    /**
     * @param  array<string, mixed>  $input  entry_date, notes, lines[] = {product_id, quantity}
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionRecord);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Nakaasi)) !== null) {
            return $existing;
        }

        $lines = is_array($input['lines'] ?? null) ? array_values(array_map(fn ($line) => is_array($line) ? $this->clean($line, []) : $line, $input['lines'])) : [];
        $input = $this->clean(array_diff_key($input, ['lines' => true]), ['notes']) + ['lines' => $lines];

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)
                ->whereIn('kind', [ProductKind::FiredGrade->value, ProductKind::ByProduct->value])],
            'lines.*.quantity' => ['nullable'],
        ], [
            'lines.required' => __('Enter how many bricks of each grade came out.'),
            'lines.min' => __('Enter how many bricks of each grade came out.'),
            'lines.*.product_id.distinct' => __('Each grade only once per entry.'),
            'lines.*.product_id.exists' => __('Choose a grade or by-product.'),
        ], [
            'entry_date' => __('date'),
            'lines.*.product_id' => __('grade'),
        ])->validate();

        $products = Product::query()->with('unit')->whereKey(array_column($data['lines'], 'product_id'))->get()->keyBy('id');
        $parsed = [];
        $unloaded = Quantity::zero();
        foreach (array_values($data['lines']) as $index => $line) {
            $product = $products[(int) $line['product_id']];
            $isBrick = $product->unit->code === Unit::BRICK;
            // A grade left empty (or 0) in the form simply did not come out this time.
            $quantity = $this->quantity($line['quantity'] ?? '', $isBrick ? 0 : $product->unit->decimals, "lines.{$index}.quantity", allowZero: true);
            if ($quantity->isZero()) {
                continue;
            }

            $parsed[] = ['product' => $product, 'quantity' => $quantity, 'brick' => $isBrick];

            if ($isBrick) {
                $unloaded = $unloaded->add($quantity);
            }
        }

        if (! $unloaded->isPositive()) {
            throw ValidationException::withMessages(['lines' => __('Enter the bricks unloaded by grade; by-products in other units come with them.')]);
        }

        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();
        $worker = $this->workerFrom($input['worker_id'] ?? null);

        return $this->posting($idempotencyKey, ProductionEntryKind::Nakaasi, function () use ($actor, $data, $date, $parsed, $unloaded, $worker, $idempotencyKey): ProductionEntry {
            $this->assertStageAllows(StageKind::FiredAwaitingUnloading, null, $date, Quantity::zero()->subtract($unloaded), 'lines');

            $entry = $this->createEntry(ProductionEntryKind::Nakaasi, $date, ['quantity' => $unloaded, 'notes' => $data['notes'], 'worker_id' => $worker?->id], $idempotencyKey, $actor);

            foreach ($parsed as $line) {
                $this->move($entry, $line['brick'] ? StageKind::FiredAwaitingUnloading : null, StageKind::Saleable, $line['product']->id, $line['quantity'], $actor);
            }

            // Unloading is paid for the bricks unloaded (grades and broken ones).
            $this->payWorker($entry, $worker, $unloaded, $actor);

            return $entry;
        });
    }
}
