<?php

namespace App\Production;

use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\ProductionEntry;
use App\Support\Kiln\CurrentKiln;
use App\Support\Quantity;
use App\Support\RunningStock;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views of brick stock, all calculated from movements: stock per stage (and per
 * grade once saleable), the never-negative check and the daily production view.
 *
 * A "slot" is a stage, plus the product for saleable stock; raw, in-firing and awaiting-Nakaasi
 * bricks are not graded yet.
 */
class BrickStock
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    /**
     * Stock of one slot (optionally as of the end of a date).
     */
    public function level(StageKind $stage, ?int $productId = null, ?CarbonInterface $asOf = null): Quantity
    {
        $in = $this->slotQuery('stage_to', $stage, $productId, $asOf)->sum('quantity');
        $out = $this->slotQuery('stage_from', $stage, $productId, $asOf)->sum('quantity');

        return Quantity::fromMilli((int) $in - (int) $out);
    }

    /**
     * Stock of every slot: the three ungraded stages, and saleable stock per product.
     *
     * @return array{raw: Quantity, in_firing: Quantity, fired_awaiting_unloading: Quantity, saleable: array<int, Quantity>}
     */
    public function levels(?CarbonInterface $asOf = null): array
    {
        $levels = [
            StageKind::Raw->value => Quantity::zero(),
            StageKind::InFiring->value => Quantity::zero(),
            StageKind::FiredAwaitingUnloading->value => Quantity::zero(),
            StageKind::Saleable->value => [],
        ];

        foreach (['stage_to' => 1, 'stage_from' => -1] as $column => $sign) {
            $rows = DB::table('brick_movements')
                ->where('kiln_id', $this->currentKiln->id())
                ->whereNotNull($column)
                ->when($asOf, fn ($q) => $q->where('movement_date', '<=', $asOf->format('Y-m-d')))
                ->groupBy($column, 'product_id')
                ->select("{$column} as stage", 'product_id')
                ->selectRaw('SUM(quantity) AS quantity')
                ->get();

            foreach ($rows as $row) {
                $amount = Quantity::fromMilli($sign * (int) $row->quantity);

                if ($row->stage === StageKind::Saleable->value) {
                    $id = (int) $row->product_id;
                    $levels[StageKind::Saleable->value][$id] = ($levels[StageKind::Saleable->value][$id] ?? Quantity::zero())->add($amount);
                } else {
                    $levels[$row->stage] = $levels[$row->stage]->add($amount);
                }
            }
        }

        return $levels;
    }

    /**
     * The lowest the slot's stock would reach, on the date or any later day, if $change were
     * added on that date. Below zero means the change would make some day impossible.
     */
    public function lowestFrom(StageKind $stage, ?int $productId, CarbonInterface $date, Quantity $change): Quantity
    {
        $nets = [];
        foreach (['stage_to' => 1, 'stage_from' => -1] as $column => $sign) {
            $rows = $this->slotQuery($column, $stage, $productId, null)
                ->groupBy('movement_date')
                ->select('movement_date')
                ->selectRaw('SUM(quantity) AS quantity')
                ->get();

            foreach ($rows as $row) {
                $day = substr((string) $row->movement_date, 0, 10);
                $nets[$day] = ($nets[$day] ?? 0) + $sign * (int) $row->quantity;
            }
        }
        ksort($nets);

        return Quantity::fromMilli(RunningStock::lowestFrom($nets, $date->format('Y-m-d'), $change->milli));
    }

    /**
     * The production of one day: stock of every slot at the start and end of the day with what
     * came in and went out, the day's entries, Pathai totals and things worth checking.
     *
     * @return array{slots: list<array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}>, entries: Collection<int, ProductionEntry>, pathai: array{made: Quantity, rejected: Quantity, accepted: Quantity}, duplicates: list<int>}
     */
    public function day(CarbonInterface $date): array
    {
        $opening = $this->levels($date->copy()->subDay());
        $closing = $this->levels($date);

        $moves = [];
        foreach (['stage_to' => 'in', 'stage_from' => 'out'] as $column => $side) {
            $rows = DB::table('brick_movements')
                ->where('kiln_id', $this->currentKiln->id())
                ->whereNotNull($column)
                ->where('movement_date', $date->format('Y-m-d'))
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

        $entries = ProductionEntry::query()
            ->where('entry_date', $date->format('Y-m-d'))
            ->with(['movements.product.unit', 'creator', 'reverser', 'materialUsage.movements.material.unit', 'worker', 'workEntry'])
            ->orderBy('id')
            ->get();

        $pathai = $entries->filter(fn (ProductionEntry $e) => $e->kind === ProductionEntryKind::Pathai && ! $e->isReversed());
        $made = Quantity::fromMilli((int) $pathai->sum(fn (ProductionEntry $e) => $e->made->milli ?? 0));
        $rejected = Quantity::fromMilli((int) $pathai->sum(fn (ProductionEntry $e) => $e->rejected->milli ?? 0));

        return [
            'slots' => $slots,
            'entries' => $entries,
            'pathai' => ['made' => $made, 'rejected' => $rejected, 'accepted' => $made->subtract($rejected)],
            'duplicates' => $this->possibleDuplicates($entries),
        ];
    }

    /**
     * Ids of entries that look entered twice: same kind, same quantities (and same team for
     * Pathai) on the same day, both still counting. Shown as a warning, never blocked.
     *
     * @param  Collection<int, ProductionEntry>  $entries
     * @return list<int>
     */
    public function possibleDuplicates(Collection $entries): array
    {
        $groups = $entries
            ->reject(fn (ProductionEntry $e) => $e->isReversed())
            ->groupBy(fn (ProductionEntry $e) => implode('|', [
                $e->kind->value,
                $e->entry_date->format('Y-m-d'),
                $e->quantity->milli,
                $e->made?->milli,
                $e->rejected?->milli,
                mb_strtolower(trim((string) $e->team)),
                $e->movements->map(fn ($m) => $m->product_id.':'.$m->quantity->milli)->sort()->implode(','),
            ]));

        return $groups->filter(fn (Collection $group) => $group->count() > 1)->flatten()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
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

    private function slotQuery(string $column, StageKind $stage, ?int $productId, ?CarbonInterface $asOf): Builder
    {
        return DB::table('brick_movements')
            ->where('kiln_id', $this->currentKiln->id())
            ->where($column, $stage->value)
            ->when($stage === StageKind::Saleable, fn ($q) => $q->where('product_id', $productId))
            ->when($asOf, fn ($q) => $q->where('movement_date', '<=', $asOf->format('Y-m-d')));
    }
}
