<?php

namespace App\Reports;

use App\Accounting\LedgerQueries;
use App\Enums\PartyRole;
use App\Models\Party;
use App\Models\Sale;
use App\Sales\CustomerPayments;
use App\Support\Money;

/**
 * Who owes the kiln now, and for how long: each customer's balance split by the age of their
 * unpaid sales (0–30, 31–60, 61–90, over 90 days). "Other" is the rest of the balance: an opening
 * balance, money refunded to them, less an advance not yet used.
 */
class ReceivablesAgeing
{
    public const BUCKETS = ['0_30' => 30, '31_60' => 60, '61_90' => 90, 'over_90' => PHP_INT_MAX];

    public function __construct(
        private readonly LedgerQueries $ledger,
        private readonly CustomerPayments $payments,
    ) {}

    /**
     * @return array{rows: list<array{party: Party, balance: Money, buckets: array<string, Money>, other: Money}>, totals: array{balance: Money, buckets: array<string, Money>, other: Money}}
     */
    public function now(): array
    {
        $customers = Party::query()->withRole(PartyRole::Customer)->orderBy('name')->get()->keyBy('id');
        $balances = $this->ledger->partyBalances($customers->keys()->all());
        $owing = array_keys(array_filter($balances, fn (Money $balance) => $balance->isPositive()));

        $states = $this->payments->forCustomers($owing)['sales'];
        $sales = Sale::query()->whereIn('customer_id', $owing)->get(['id', 'customer_id', 'sale_date'])->groupBy('customer_id');
        $today = now()->startOfDay();

        $rows = [];
        $totals = ['balance' => Money::zero(), 'buckets' => array_fill_keys(array_keys(self::BUCKETS), Money::zero()), 'other' => Money::zero()];

        foreach ($owing as $id) {
            $buckets = array_fill_keys(array_keys(self::BUCKETS), Money::zero());
            $inSales = Money::zero();

            foreach ($sales->get($id, collect()) as $sale) {
                $outstanding = $states[$sale->id]['outstanding'] ?? Money::zero();
                if (! $outstanding->isPositive()) {
                    continue;
                }

                $age = (int) $sale->sale_date->diffInDays($today);
                $bucket = array_key_first(array_filter(self::BUCKETS, fn (int $limit) => $age <= $limit));
                $buckets[$bucket] = $buckets[$bucket]->add($outstanding);
                $inSales = $inSales->add($outstanding);
            }

            $row = ['party' => $customers[$id], 'balance' => $balances[$id], 'buckets' => $buckets, 'other' => $balances[$id]->subtract($inSales)];
            $rows[] = $row;

            $totals['balance'] = $totals['balance']->add($row['balance']);
            $totals['other'] = $totals['other']->add($row['other']);
            foreach ($buckets as $key => $amount) {
                $totals['buckets'][$key] = $totals['buckets'][$key]->add($amount);
            }
        }

        usort($rows, fn (array $a, array $b) => $b['balance']->paisa <=> $a['balance']->paisa);

        return ['rows' => $rows, 'totals' => $totals];
    }

    public static function bucketLabel(string $key): string
    {
        return match ($key) {
            '0_30' => __('Up to 30 days'),
            '31_60' => __('31–60 days'),
            '61_90' => __('61–90 days'),
            default => __('Over 90 days'),
        };
    }
}
