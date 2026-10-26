<?php

namespace App\Sales;

use App\Enums\ProductKind;
use App\Enums\SaleStatus;
use App\Enums\StageKind;
use App\Models\Product;
use App\Production\BrickStock;
use App\Support\Kiln\CurrentKiln;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Brick stock as sales see it: what was delivered on each sale line, what open sales still have
 * to collect (reserved), and what is free to sell (in stock now minus reserved).
 *
 * Fired grades and by-products are sold from "ready for sale" stock by product. Raw bricks
 * (Kachi), when a kiln marks them saleable, come from the raw stage, which is not graded.
 */
class SaleStock
{
    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly BrickStock $brickStock,
    ) {}

    /**
     * The stage (and product, for graded stock) a product is sold from.
     *
     * @return array{0: StageKind, 1: int|null}
     */
    public static function slot(Product $product): array
    {
        return $product->kind === ProductKind::RawBrick ? [StageKind::Raw, null] : [StageKind::Saleable, $product->id];
    }

    /**
     * Quantity delivered on each sale line (thousandths); reversed deliveries don't count.
     *
     * @param  list<int>  $saleLineIds
     * @return array<int, int>
     */
    public function delivered(array $saleLineIds): array
    {
        if ($saleLineIds === []) {
            return [];
        }

        $rows = DB::table('sale_dispatch_lines as dl')
            ->join('sale_dispatches as d', 'd.id', '=', 'dl.sale_dispatch_id')
            ->where('dl.kiln_id', $this->currentKiln->id())
            ->whereNull('d.reversed_at')
            ->whereIn('dl.sale_line_id', $saleLineIds)
            ->groupBy('dl.sale_line_id')
            ->selectRaw('dl.sale_line_id, SUM(dl.quantity) AS quantity')
            ->pluck('quantity', 'sale_line_id');

        $delivered = array_fill_keys($saleLineIds, 0);
        foreach ($rows as $lineId => $quantity) {
            $delivered[(int) $lineId] = (int) $quantity;
        }

        return $delivered;
    }

    /**
     * What open sales still have to collect, per product (thousandths). Closed and cancelled
     * sales reserve nothing.
     *
     * @return array<int, int>
     */
    public function reserved(): array
    {
        $delivered = DB::table('sale_dispatch_lines as dl')
            ->join('sale_dispatches as d', 'd.id', '=', 'dl.sale_dispatch_id')
            ->whereNull('d.reversed_at')
            ->groupBy('dl.sale_line_id')
            ->selectRaw('dl.sale_line_id, SUM(dl.quantity) AS quantity');

        return DB::table('sale_lines as l')
            ->join('sales as s', 's.id', '=', 'l.sale_id')
            ->leftJoinSub($delivered, 'dv', 'dv.sale_line_id', '=', 'l.id')
            ->where('l.kiln_id', $this->currentKiln->id())
            ->where('s.status', SaleStatus::Open->value)
            ->groupBy('l.product_id')
            // CAST: the columns are unsigned, and the difference must never wrap around.
            ->selectRaw('l.product_id, SUM(CAST(l.quantity AS SIGNED) - COALESCE(dv.quantity, 0)) AS quantity')
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity) => max(0, (int) $quantity))
            ->mapWithKeys(fn (int $quantity, $productId) => [(int) $productId => $quantity])
            ->all();
    }

    /**
     * Stock, reserved and free to sell for each product.
     *
     * @param  iterable<Product>  $products
     * @return array<int, array{stock: Quantity, reserved: Quantity, free: Quantity}>
     */
    public function availability(iterable $products): array
    {
        $levels = $this->brickStock->levels();
        $reserved = $this->reserved();

        $result = [];
        foreach ($products as $product) {
            [$stage, $productId] = self::slot($product);
            $stock = $stage === StageKind::Saleable
                ? ($levels[StageKind::Saleable->value][$productId] ?? Quantity::zero())
                : $levels[$stage->value];
            $held = Quantity::fromMilli($reserved[$product->id] ?? 0);

            $result[$product->id] = ['stock' => $stock, 'reserved' => $held, 'free' => $stock->subtract($held)];
        }

        return $result;
    }
}
