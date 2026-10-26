<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Models\Party;
use App\Models\Product;
use App\Sales\CustomerPayments;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales made in a period (by sale date, cancelled sales left out): by grade, by customer and by
 * sales agent, with what was delivered of them, their carriage, the bricks not delivered
 * (credit notes) and what is still to be paid.
 */
class SalesSummary
{
    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly CustomerPayments $payments,
    ) {}

    /**
     * @return array{count: int, bricks: Money, carriage: Money, credits: Money, billed: Money, paid: Money, outstanding: Money, by_grade: list<array{product: Product, ordered: Quantity, delivered: Quantity, amount: Money}>, by_customer: list<array{party: Party, count: int, billed: Money, outstanding: Money}>, by_agent: list<array{agent: Party|null, count: int, billed: Money}>}
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        // Totals come from the database; only the payment of each sale is worked out here.
        $kilnId = $this->currentKiln->id();
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        $inPeriod = fn (Builder $query, string $sale = 's') => $query
            ->where("{$sale}.kiln_id", $kilnId)
            ->where("{$sale}.status", '!=', SaleStatus::Cancelled->value)
            ->whereBetween("{$sale}.sale_date", $range);

        $sales = $inPeriod(DB::table('sales as s'))->orderBy('s.sale_date')->get(['s.id', 's.customer_id', 's.agent_id', 's.total']);

        $ordered = $inPeriod(DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id'))
            ->groupBy('l.product_id')
            ->selectRaw('l.product_id, SUM(l.quantity) AS quantity, SUM(l.amount) AS amount')
            ->get()->keyBy('product_id');
        $delivered = $inPeriod(DB::table('sale_dispatch_lines as dl')
            ->join('sale_dispatches as d', 'd.id', '=', 'dl.sale_dispatch_id')
            ->join('sale_lines as l', 'l.id', '=', 'dl.sale_line_id')
            ->join('sales as s', 's.id', '=', 'l.sale_id'))
            ->whereNull('d.reversed_at')
            ->groupBy('l.product_id')
            ->selectRaw('l.product_id, SUM(dl.quantity) AS quantity')
            ->pluck('quantity', 'product_id');
        $carriage = (int) $inPeriod(DB::table('sale_dispatches as d')->join('sales as s', 's.id', '=', 'd.sale_id'))
            ->whereNull('d.reversed_at')->sum('d.carriage');
        $credits = (int) $inPeriod(DB::table('journal_entries as e')->join('sales as s', 's.credit_entry_id', '=', 'e.id'))->sum('e.total');

        $states = $this->payments->forCustomers($sales->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values()->all())['sales'];
        $billed = fn (object $sale): int => isset($states[(int) $sale->id]) ? $states[(int) $sale->id]['due']->paisa : (int) $sale->total;
        $outstanding = fn (object $sale): int => isset($states[(int) $sale->id]) ? $states[(int) $sale->id]['outstanding']->paisa : 0;
        $paid = fn (object $sale): int => isset($states[(int) $sale->id]) ? $states[(int) $sale->id]['paid']->paisa : 0;

        $products = Product::query()->with('unit')->whereKey($ordered->keys()->all())->get();
        $byGrade = $products
            ->sortBy(fn (Product $product) => [$product->sort_order, $product->name])
            ->map(fn (Product $product) => [
                'product' => $product,
                'ordered' => Quantity::fromMilli((int) $ordered[$product->id]->quantity),
                'delivered' => Quantity::fromMilli((int) ($delivered[$product->id] ?? 0)),
                'amount' => Money::fromPaisa((int) $ordered[$product->id]->amount),
            ])->values()->all();

        $parties = Party::query()->whereKey($sales->pluck('customer_id')->merge($sales->pluck('agent_id'))->filter()->unique()->all())->get()->keyBy('id');

        $byCustomer = $sales->groupBy('customer_id')->map(fn (Collection $group, $customerId) => [
            'party' => $parties[(int) $customerId],
            'count' => $group->count(),
            'billed' => Money::fromPaisa((int) $group->sum($billed)),
            'outstanding' => Money::fromPaisa((int) $group->sum($outstanding)),
        ])->sortByDesc(fn (array $row) => $row['billed']->paisa)->values()->all();

        $byAgent = $sales->groupBy(fn (object $sale) => (int) $sale->agent_id)->map(fn (Collection $group, $agentId) => [
            'agent' => $agentId ? ($parties[(int) $agentId] ?? null) : null,
            'count' => $group->count(),
            'billed' => Money::fromPaisa((int) $group->sum($billed)),
        ])->sortByDesc(fn (array $row) => $row['billed']->paisa)->values()->all();

        return [
            'count' => $sales->count(),
            'bricks' => Money::fromPaisa((int) $sales->sum('total')),
            'carriage' => Money::fromPaisa($carriage),
            'credits' => Money::fromPaisa($credits),
            'billed' => Money::fromPaisa((int) $sales->sum($billed)),
            'paid' => Money::fromPaisa((int) $sales->sum($paid)),
            'outstanding' => Money::fromPaisa((int) $sales->sum($outstanding)),
            'by_grade' => $byGrade,
            'by_customer' => $byCustomer,
            'by_agent' => $byAgent,
        ];
    }
}
