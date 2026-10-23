<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Models\SaleLine;
use App\Sales\CustomerPayments;
use App\Sales\SaleStock;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sales made in a period (by sale date, cancelled sales left out): by grade, by customer and by
 * sales agent, with what was delivered of them, their carriage, the bricks not delivered
 * (credit notes) and what is still to be paid.
 */
class SalesSummary
{
    public function __construct(
        private readonly SaleStock $saleStock,
        private readonly CustomerPayments $payments,
    ) {}

    /**
     * @return array{count: int, bricks: Money, carriage: Money, credits: Money, billed: Money, paid: Money, outstanding: Money, by_grade: list<array{product: Product, ordered: Quantity, delivered: Quantity, amount: Money}>, by_customer: list<array{party: Party, count: int, billed: Money, outstanding: Money}>, by_agent: list<array{agent: Party|null, count: int, billed: Money}>}
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $sales = Sale::query()
            ->with(['customer', 'agent', 'lines.product.unit', 'creditEntry'])
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->whereBetween('sale_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('sale_date')
            ->get();

        $lines = $sales->flatMap(fn (Sale $sale) => $sale->lines);
        $delivered = $this->saleStock->delivered($lines->pluck('id')->all());
        $carriage = SaleDispatch::query()->whereIn('sale_id', $sales->pluck('id'))->whereNull('reversed_at')->get(['sale_id', 'carriage'])->groupBy('sale_id');
        $states = $this->payments->forCustomers($sales->pluck('customer_id')->unique()->values()->all())['sales'];

        $byGrade = $lines->groupBy('product_id')->map(fn (Collection $group) => [
            'product' => $group->first()->product,
            'ordered' => Quantity::fromMilli((int) $group->sum(fn (SaleLine $line) => $line->quantity->milli)),
            'delivered' => Quantity::fromMilli((int) $group->sum(fn (SaleLine $line) => $delivered[$line->id] ?? 0)),
            'amount' => Money::sum($group->map(fn (SaleLine $line) => $line->amount)),
        ])->sortBy(fn (array $row) => [$row['product']->sort_order, $row['product']->name])->values()->all();

        $billed = fn (Sale $sale) => $states[$sale->id]['due'] ?? $sale->total;

        $byCustomer = $sales->groupBy('customer_id')->map(fn (Collection $group) => [
            'party' => $group->first()->customer,
            'count' => $group->count(),
            'billed' => Money::sum($group->map($billed)),
            'outstanding' => Money::sum($group->map(fn (Sale $sale) => $states[$sale->id]['outstanding'] ?? Money::zero())),
        ])->sortByDesc(fn (array $row) => $row['billed']->paisa)->values()->all();

        $byAgent = $sales->groupBy(fn (Sale $sale) => (int) $sale->agent_id)->map(fn (Collection $group) => [
            'agent' => $group->first()->agent,
            'count' => $group->count(),
            'billed' => Money::sum($group->map($billed)),
        ])->sortByDesc(fn (array $row) => $row['billed']->paisa)->values()->all();

        return [
            'count' => $sales->count(),
            'bricks' => Money::sum($sales->map(fn (Sale $sale) => $sale->total)),
            'carriage' => Money::sum($carriage->flatten()->map(fn (SaleDispatch $dispatch) => $dispatch->carriage)),
            'credits' => Money::sum($sales->map(fn (Sale $sale) => $sale->creditEntry->total ?? Money::zero())),
            'billed' => Money::sum($sales->map($billed)),
            'paid' => Money::sum($sales->map(fn (Sale $sale) => $states[$sale->id]['paid'] ?? Money::zero())),
            'outstanding' => Money::sum($sales->map(fn (Sale $sale) => $states[$sale->id]['outstanding'] ?? Money::zero())),
            'by_grade' => $byGrade,
            'by_customer' => $byCustomer,
            'by_agent' => $byAgent,
        ];
    }
}
