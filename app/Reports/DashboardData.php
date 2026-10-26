<?php

namespace App\Reports;

use App\Accounting\BooksLock;
use App\Accounting\LedgerQueries;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Labour\WorkerLedger;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Production\BrickStock;
use App\Sales\SaleStock;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The figures on the dashboard: only the sections the user may see (a data-entry user sees
 * production; the owner sees everything). Everything comes from the same books and stock as
 * the reports.
 */
class DashboardData
{
    public function __construct(
        private readonly CashDay $cashDay,
        private readonly LedgerQueries $ledger,
        private readonly WorkerLedger $workers,
        private readonly ProductionSummary $production,
        private readonly BrickStock $brickStock,
        private readonly SaleStock $saleStock,
        private readonly FinancialStatements $statements,
        private readonly BooksLock $booksLock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $today = now()->startOfDay();
        $data = [];

        if ($user->can(Permission::AccountsView->value)) {
            $assets = array_filter($this->cashDay->forDate($today), fn (array $row) => $row['account']->ledgerAccount->type->isDebitNormal());
            $data['money'] = [
                'cash' => Money::sum(array_column($assets, 'closing')),
                'in' => Money::sum(array_column($assets, 'in')),
                'out' => Money::sum(array_column($assets, 'out')),
            ];
        }

        if ($user->can(Permission::PartiesView->value)) {
            $data['parties'] = $this->ledger->partyTotals();
        }

        if ($user->can(Permission::LabourView->value)) {
            $data['wages_due'] = $this->workers->totals()['owed_to_workers'];
        }

        if ($user->can(Permission::SalesView->value)) {
            $sold = Sale::query()->where('sale_date', $today->format('Y-m-d'))->where('status', '!=', SaleStatus::Cancelled->value)->get(['total']);
            $data['sales'] = [
                'count' => $sold->count(),
                'amount' => Money::sum($sold->map(fn (Sale $sale) => $sale->total)),
                'open' => Sale::query()->where('status', SaleStatus::Open->value)->count(),
            ];
        }

        if ($user->can(Permission::ProductionView->value)) {
            $day = $this->production->forPeriod($today, $today);
            $levels = $this->brickStock->levels();
            $brickProducts = Product::query()->whereHas('unit', fn ($q) => $q->where('code', Unit::BRICK))->pluck('id')->all();
            $reserved = $this->saleStock->reserved();
            $ready = 0;
            $held = 0;
            foreach ($brickProducts as $id) {
                $ready += ($levels['saleable'][$id] ?? Quantity::zero())->milli;
                $held += $reserved[$id] ?? 0;
            }

            $data['production'] = [
                'made' => $day['pathai']['made'],
                'accepted' => $day['pathai']['accepted'],
                'loaded' => $day['loaded'],
                'unloaded' => $day['unloaded'],
                'delivered' => Quantity::fromMilli((int) array_sum(array_map(fn (array $d) => $d['delivered']->milli, $day['days']))),
                'raw' => $levels['raw'],
                'in_kiln' => $levels['in_firing']->add($levels['fired_awaiting_unloading']),
                'ready' => Quantity::fromMilli($ready),
                'free' => Quantity::fromMilli($ready - $held),
            ];
        }

        if ($user->can(Permission::FinancialReportsView->value)) {
            $months = [];
            foreach (range(5, 0) as $back) {
                $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($back);
                $end = $back === 0 ? $today : $start->copy()->endOfMonth()->startOfDay();
                $pnl = $this->statements->profitAndLoss($start, $end);
                $months[] = ['label' => $start->translatedFormat('M Y'), 'sales' => $pnl['total_income'], 'costs' => $pnl['total_expenses']];
            }

            $thisMonth = $this->statements->profitAndLoss($today->copy()->startOfMonth(), $today);
            $data['finance'] = [
                'months' => $months,
                'month_costs' => array_map(fn (array $group) => ['label' => $group['label'], 'amount' => $group['total']], $thisMonth['groups']),
                'month_income' => $thisMonth['total_income'],
                'month_profit' => $thisMonth['net'],
            ];
        }

        if ($user->can(Permission::DaysClose->value)) {
            $through = $this->booksLock->closedThrough();
            $data['day'] = ['closed_through' => $through, 'today_closed' => $through !== null && $this->isOnOrAfter($through, $today)];
        }

        return $data;
    }

    private function isOnOrAfter(Carbon $date, CarbonInterface $day): bool
    {
        return $date->format('Y-m-d') >= $day->format('Y-m-d');
    }
}
