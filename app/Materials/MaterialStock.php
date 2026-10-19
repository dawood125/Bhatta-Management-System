<?php

namespace App\Materials;

use App\Enums\MaterialMovementType;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views of material stock, all calculated from movements: stock levels, average cost,
 * the stock card of one material and the material cost report. Nothing is stored as a total.
 *
 * Valuation is weighted average cost in posting order: what is taken out is valued at the
 * stock's current average, and taking out everything takes out all of the value.
 */
class MaterialStock
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    /**
     * Quantity and value in stock per material (optionally as of the end of a date).
     *
     * @param  list<int>  $materialIds
     * @return array<int, array{quantity: Quantity, value: Money}>
     */
    public function levels(array $materialIds, ?CarbonInterface $asOf = null): array
    {
        $result = [];
        foreach ($materialIds as $id) {
            $result[$id] = ['quantity' => Quantity::zero(), 'value' => Money::zero()];
        }

        if ($materialIds === []) {
            return $result;
        }

        $rows = DB::table('material_movements')
            ->where('kiln_id', $this->currentKiln->id())
            ->whereIn('material_id', $materialIds)
            ->when($asOf, fn ($q) => $q->where('movement_date', '<=', $asOf->format('Y-m-d')))
            ->groupBy('material_id')
            ->select('material_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) AS quantity, SUM(value_in) - SUM(value_out) AS value')
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->material_id] = [
                'quantity' => Quantity::fromMilli((int) $row->quantity),
                'value' => Money::fromPaisa((int) $row->value),
            ];
        }

        return $result;
    }

    /**
     * Average cost of one unit, rounded to the paisa for display; null when nothing is in stock.
     *
     * @param  array{quantity: Quantity, value: Money}  $level
     */
    public function averageCost(array $level): ?Money
    {
        if (! $level['quantity']->isPositive()) {
            return null;
        }

        return Money::fromPaisa(BigDecimal::of($level['value']->paisa)
            ->multipliedBy(Quantity::SCALE)
            ->dividedBy($level['quantity']->milli, 0, RoundingMode::HalfUp)
            ->toInt());
    }

    /**
     * Value of taking a quantity out of stock at its average cost. Taking out the whole stock
     * takes out all of its value, so no rounding remainder is ever left behind.
     *
     * @param  array{quantity: Quantity, value: Money}  $level
     */
    public function valueOut(Quantity $quantity, array $level): Money
    {
        if ($quantity->equals($level['quantity'])) {
            return $level['value'];
        }

        if (! $level['quantity']->isPositive()) {
            return Money::zero();
        }

        return Money::fromPaisa(BigDecimal::of($level['value']->paisa)
            ->multipliedBy($quantity->milli)
            ->dividedBy($level['quantity']->milli, 0, RoundingMode::HalfUp)
            ->toInt());
    }

    /**
     * The lowest the stock would reach, on the given date or any later day, if $change were
     * added on that date. Below zero means the change would make some day's stock negative.
     */
    public function lowestFrom(Material $material, CarbonInterface $date, Quantity $change): Quantity
    {
        $day = $date->format('Y-m-d');

        $nets = DB::table('material_movements')
            ->where('kiln_id', $this->currentKiln->id())
            ->where('material_id', $material->id)
            ->groupBy('movement_date')
            ->orderBy('movement_date')
            ->select('movement_date')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) AS net')
            ->get();

        $running = 0;
        $atDate = null;
        $lowest = null;

        foreach ($nets as $row) {
            $rowDay = substr((string) $row->movement_date, 0, 10);

            if ($rowDay > $day && $atDate === null) {
                $atDate = $running + $change->milli;
                $lowest = $atDate;
            }

            $running += (int) $row->net;

            if ($rowDay >= $day) {
                $atDate ??= $running + $change->milli;
                $lowest = min($lowest ?? PHP_INT_MAX, $running + $change->milli);
            }
        }

        $lowest ??= $running + $change->milli;

        return Quantity::fromMilli($lowest);
    }

    /**
     * Stock card of one material for a period: stock before it, each movement with the running
     * quantity and value, and the closing stock.
     *
     * @return array{opening: array{quantity: Quantity, value: Money}, rows: list<array{movement: MaterialMovement, quantity: Quantity, value: Money}>, closing: array{quantity: Quantity, value: Money}}
     */
    public function card(Material $material, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = $this->levels([$material->id], $from->copy()->subDay())[$material->id];

        $movements = MaterialMovement::query()
            ->where('material_id', $material->id)
            ->whereBetween('movement_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('movement_date')
            ->orderBy('id')
            ->with(['entry', 'reversalOf.entry', 'reversalOf.purchaseLine', 'purchaseLine.purchase.supplier'])
            ->get();

        $quantity = $opening['quantity'];
        $value = $opening['value'];
        $rows = [];

        foreach ($movements as $movement) {
            $quantity = $quantity->add($movement->netQuantity());
            $value = $value->add($movement->netValue());
            $rows[] = ['movement' => $movement, 'quantity' => $quantity, 'value' => $value];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => ['quantity' => $quantity, 'value' => $value]];
    }

    /**
     * Material cost report for a period, per material: stock at the start, bought (including
     * opening stock added), used, shortages, and stock at the end. A reversal counts against
     * the movement it reverses, so a reversed purchase is simply not bought.
     *
     * @return Collection<int, array{material: Material, opening: array{quantity: Quantity, value: Money}, bought: array{quantity: Quantity, value: Money}, used: array{quantity: Quantity, value: Money}, shortage: array{quantity: Quantity, value: Money}, closing: array{quantity: Quantity, value: Money}}>
     */
    public function report(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $materials = Material::query()->with('unit')->orderBy('sort_order')->orderBy('name')->get();
        $ids = $materials->pluck('id')->all();
        $opening = $this->levels($ids, $from->copy()->subDay());

        // Net change per material and kind of movement (a reversal takes the kind of its original).
        $rows = DB::table('material_movements as m')
            ->leftJoin('material_movements as o', 'o.id', '=', 'm.reversal_of_id')
            ->where('m.kiln_id', $this->currentKiln->id())
            ->whereBetween('m.movement_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('m.material_id', DB::raw('COALESCE(o.type, m.type)'))
            ->select('m.material_id')
            ->selectRaw('COALESCE(o.type, m.type) AS kind')
            ->selectRaw('SUM(m.quantity_in) - SUM(m.quantity_out) AS quantity, SUM(m.value_in) - SUM(m.value_out) AS value')
            ->get()
            ->groupBy('material_id');

        return $materials->map(function (Material $material) use ($opening, $rows) {
            $sums = ['bought' => [0, 0], 'used' => [0, 0], 'shortage' => [0, 0]];

            foreach ($rows[$material->id] ?? [] as $row) {
                $bucket = match (MaterialMovementType::from($row->kind)) {
                    MaterialMovementType::Purchase, MaterialMovementType::Opening => 'bought',
                    MaterialMovementType::Usage => 'used',
                    MaterialMovementType::Shortage, MaterialMovementType::Reversal => 'shortage',
                };
                // "Used" and "shortage" are shown as positive amounts taken out of stock.
                $sign = $bucket === 'bought' ? 1 : -1;
                $sums[$bucket][0] += $sign * (int) $row->quantity;
                $sums[$bucket][1] += $sign * (int) $row->value;
            }

            $start = $opening[$material->id];
            $closingQuantity = $start['quantity']->milli + $sums['bought'][0] - $sums['used'][0] - $sums['shortage'][0];
            $closingValue = $start['value']->paisa + $sums['bought'][1] - $sums['used'][1] - $sums['shortage'][1];

            $pair = fn (array $sum) => ['quantity' => Quantity::fromMilli($sum[0]), 'value' => Money::fromPaisa($sum[1])];

            return [
                'material' => $material,
                'opening' => $start,
                'bought' => $pair($sums['bought']),
                'used' => $pair($sums['used']),
                'shortage' => $pair($sums['shortage']),
                'closing' => ['quantity' => Quantity::fromMilli($closingQuantity), 'value' => Money::fromPaisa($closingValue)],
            ];
        });
    }
}
