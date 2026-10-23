<?php

namespace App\Reports;

use App\Models\LedgerAccount;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;

/**
 * What 1,000 bricks cost the kiln in a period: materials and fuel used, labour and running
 * expenses of the period, divided by the bricks that came out of the kiln (Nakaasi) in it; and
 * next to it what 1,000 bricks sold for (brick sales of the period ÷ bricks delivered in it).
 *
 * An indicator, not an exact unit cost: costs of a period also go into bricks still raw or in
 * the kiln, which is why a whole month or more gives the most useful figure.
 */
class CostPerThousand
{
    public function __construct(
        private readonly FinancialStatements $statements,
        private readonly ProductionSummary $production,
    ) {}

    /**
     * @return array{unloaded: Quantity, delivered: Quantity, costs: list<array{key: string, label: string, amount: Money, per_thousand: Money|null}>, total: Money, total_per_thousand: Money|null, sales: Money, sales_per_thousand: Money|null, margin_per_thousand: Money|null}
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $pnl = $this->statements->profitAndLoss($from, $to);
        $production = $this->production->forPeriod($from, $to);

        $unloaded = $production['unloaded'];
        $delivered = Quantity::fromMilli((int) array_sum(array_map(fn (array $day) => $day['delivered']->milli, $production['days'])));

        $costs = [];
        foreach ([FinancialStatements::GROUP_MATERIALS, FinancialStatements::GROUP_LABOUR, FinancialStatements::GROUP_EXPENSES] as $key) {
            $group = collect($pnl['groups'])->firstWhere('key', $key);
            $amount = $group['total'] ?? Money::zero();
            $costs[] = ['key' => $key, 'label' => FinancialStatements::groupLabel($key), 'amount' => $amount, 'per_thousand' => self::perThousand($amount, $unloaded)];
        }

        $total = Money::sum(array_column($costs, 'amount'));
        $sales = collect($pnl['income'])->first(fn (array $row) => $row['account']->system_key === LedgerAccount::SALES)['amount'] ?? Money::zero();
        $totalPerThousand = self::perThousand($total, $unloaded);
        $salesPerThousand = self::perThousand($sales, $delivered);

        return [
            'unloaded' => $unloaded,
            'delivered' => $delivered,
            'costs' => $costs,
            'total' => $total,
            'total_per_thousand' => $totalPerThousand,
            'sales' => $sales,
            'sales_per_thousand' => $salesPerThousand,
            'margin_per_thousand' => $salesPerThousand !== null && $totalPerThousand !== null ? $salesPerThousand->subtract($totalPerThousand) : null,
        ];
    }

    /** Amount × 1,000 ÷ bricks, rounded half up to the paisa; null when there are no bricks. */
    public static function perThousand(Money $amount, Quantity $bricks): ?Money
    {
        $whole = intdiv($bricks->milli, 1000);

        return $whole > 0 ? $amount->timesQuantity('1000', $whole) : null;
    }
}
