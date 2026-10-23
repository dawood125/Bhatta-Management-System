<?php

namespace App\Reports;

use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\Unit;
use App\Production\BrickStock;
use App\Support\Kiln\CurrentKiln;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Production of a period: stock of every stage (and grade) at the start and end with what came
 * in and went out, the totals of each kind of work (Pathai, loading, firing, Nakaasi), what
 * Nakaasi gave by grade, what was delivered to customers by grade, and a line per day.
 * Reversed entries and deliveries are left out of the totals; stock levels include everything.
 */
class ProductionSummary
{
    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly BrickStock $brickStock,
    ) {}

    /**
     * @return array{slots: list<array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}>, pathai: array{made: Quantity, rejected: Quantity, accepted: Quantity}, loaded: Quantity, fired: Quantity, unloaded: Quantity, unloaded_by_product: array<int, Quantity>, delivered_by_product: array<int, Quantity>, days: list<array{date: string, made: Quantity, accepted: Quantity, loaded: Quantity, fired: Quantity, unloaded: Quantity, delivered: Quantity}>}
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $kilnId = $this->currentKiln->id();
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        // Stock at the start and the end, and what moved in between, per slot.
        $opening = $this->brickStock->levels($from->copy()->subDay());
        $closing = $this->brickStock->levels($to);
        $moves = [];
        foreach (['stage_to' => 'in', 'stage_from' => 'out'] as $column => $side) {
            $rows = DB::table('brick_movements')
                ->where('kiln_id', $kilnId)
                ->whereNotNull($column)
                ->whereBetween('movement_date', $range)
                ->groupBy($column, 'product_id')
                ->select("{$column} as stage", 'product_id')
                ->selectRaw('SUM(quantity) AS quantity')
                ->get();
            foreach ($rows as $row) {
                $key = $row->stage === StageKind::Saleable->value ? 'saleable:'.(int) $row->product_id : $row->stage;
                $moves[$key][$side] = ($moves[$key][$side] ?? 0) + (int) $row->quantity;
            }
        }

        $slots = [];
        foreach ([StageKind::Raw, StageKind::InFiring, StageKind::FiredAwaitingUnloading] as $stage) {
            $slots[] = $this->slot($stage, null, $opening[$stage->value], $closing[$stage->value], $moves[$stage->value] ?? []);
        }
        $productIds = array_unique([...array_keys($opening[StageKind::Saleable->value]), ...array_keys($closing[StageKind::Saleable->value])]);
        sort($productIds);
        foreach ($productIds as $id) {
            $slots[] = $this->slot(StageKind::Saleable, $id, $opening[StageKind::Saleable->value][$id] ?? Quantity::zero(), $closing[StageKind::Saleable->value][$id] ?? Quantity::zero(), $moves['saleable:'.$id] ?? []);
        }

        // Work done, per kind and per day (entries that still count).
        $entries = DB::table('production_entries')
            ->where('kiln_id', $kilnId)
            ->whereNull('reversed_at')
            ->whereBetween('entry_date', $range)
            ->groupBy('entry_date', 'kind')
            ->select('entry_date', 'kind')
            ->selectRaw('SUM(quantity) AS quantity, SUM(COALESCE(made, 0)) AS made, SUM(COALESCE(rejected, 0)) AS rejected')
            ->get();

        $delivered = DB::table('sale_dispatch_lines as dl')
            ->join('sale_dispatches as d', 'd.id', '=', 'dl.sale_dispatch_id')
            ->join('sale_lines as l', 'l.id', '=', 'dl.sale_line_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->where('dl.kiln_id', $kilnId)
            ->whereNull('d.reversed_at')
            ->whereBetween('d.dispatch_date', $range)
            ->groupBy('d.dispatch_date', 'l.product_id', 'u.code')
            ->select('d.dispatch_date', 'l.product_id', 'u.code')
            ->selectRaw('SUM(dl.quantity) AS quantity')
            ->get();

        $days = [];
        $totals = ['made' => 0, 'rejected' => 0, 'accepted' => 0, 'loaded' => 0, 'fired' => 0, 'unloaded' => 0];
        foreach ($entries as $row) {
            $date = substr((string) $row->entry_date, 0, 10);
            $days[$date] ??= ['made' => 0, 'accepted' => 0, 'loaded' => 0, 'fired' => 0, 'unloaded' => 0, 'delivered' => 0];
            $quantity = (int) $row->quantity;

            // Pathai's quantity is the accepted bricks; adjustments are not work done.
            $column = match (ProductionEntryKind::from($row->kind)) {
                ProductionEntryKind::Pathai => 'accepted',
                ProductionEntryKind::Loading => 'loaded',
                ProductionEntryKind::Firing => 'fired',
                ProductionEntryKind::Nakaasi => 'unloaded',
                ProductionEntryKind::Adjustment => null,
            };
            if ($column === null) {
                continue;
            }

            $days[$date][$column] += $quantity;
            $totals[$column] += $quantity;
            if ($column === 'accepted') {
                $days[$date]['made'] += (int) $row->made;
                $totals['made'] += (int) $row->made;
                $totals['rejected'] += (int) $row->rejected;
            }
        }

        $deliveredByProduct = [];
        foreach ($delivered as $row) {
            $deliveredByProduct[(int) $row->product_id] = ($deliveredByProduct[(int) $row->product_id] ?? 0) + (int) $row->quantity;
            if ($row->code === Unit::BRICK) {
                $date = substr((string) $row->dispatch_date, 0, 10);
                $days[$date] ??= ['made' => 0, 'accepted' => 0, 'loaded' => 0, 'fired' => 0, 'unloaded' => 0, 'delivered' => 0];
                $days[$date]['delivered'] += (int) $row->quantity;
            }
        }
        ksort($days);

        // What Nakaasi gave, by grade and by-product.
        $unloadedByProduct = DB::table('brick_movements as m')
            ->join('production_entries as e', 'e.id', '=', 'm.production_entry_id')
            ->where('m.kiln_id', $kilnId)
            ->where('e.kind', ProductionEntryKind::Nakaasi->value)
            ->whereNull('e.reversed_at')
            ->whereNull('m.reversal_of_id')
            ->whereBetween('e.entry_date', $range)
            ->groupBy('m.product_id')
            ->selectRaw('m.product_id, SUM(m.quantity) AS quantity')
            ->pluck('quantity', 'product_id');

        $q = fn (int $milli) => Quantity::fromMilli($milli);

        return [
            'slots' => $slots,
            'pathai' => ['made' => $q($totals['made']), 'rejected' => $q($totals['rejected']), 'accepted' => $q($totals['accepted'])],
            'loaded' => $q($totals['loaded']),
            'fired' => $q($totals['fired']),
            'unloaded' => $q($totals['unloaded']),
            'unloaded_by_product' => $unloadedByProduct->mapWithKeys(fn ($quantity, $id) => [(int) $id => $q((int) $quantity)])->all(),
            'delivered_by_product' => array_map($q, $deliveredByProduct),
            'days' => array_map(fn (string $date, array $day) => ['date' => $date] + array_map($q, $day), array_keys($days), array_values($days)),
        ];
    }

    /**
     * @param  array{in?: int, out?: int}  $moves
     * @return array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}
     */
    private function slot(StageKind $stage, ?int $productId, Quantity $opening, Quantity $closing, array $moves): array
    {
        return [
            'stage' => $stage,
            'product_id' => $productId,
            'opening' => $opening,
            'in' => Quantity::fromMilli($moves['in'] ?? 0),
            'out' => Quantity::fromMilli($moves['out'] ?? 0),
            'closing' => $closing,
        ];
    }
}
