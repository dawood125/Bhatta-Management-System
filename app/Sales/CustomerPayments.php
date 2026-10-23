<?php

namespace App\Sales;

use App\Enums\JournalType;
use App\Enums\PaymentStatus;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Models\SaleReceipt;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * How much of each sale a customer has paid, worked out from the customer's account the way a
 * hand ledger (khata) is kept. Money received with a sale, or from the sale's page, pays that
 * sale first; everything else the customer paid or was credited (receipt vouchers, advances,
 * write-offs, and anything more than such a sale needed) pays what they owe, oldest first.
 * Nothing but those links is stored, so it always agrees with the ledger.
 *
 * What a sale asks for = its invoice + carriage of its deliveries − its credit note. Other
 * amounts the customer owes (an opening balance, money refunded to them) are paid in date order
 * with the sales. Reversed entries and their reversals cancel out and are left out.
 */
class CustomerPayments
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    /**
     * @param  list<int>  $customerIds
     * @return array{sales: array<int, array{due: Money, paid: Money, outstanding: Money, status: PaymentStatus|null}>, unapplied: array<int, Money>}
     */
    public function forCustomers(array $customerIds): array
    {
        $customerIds = array_values(array_unique($customerIds));
        if ($customerIds === []) {
            return ['sales' => [], 'unapplied' => []];
        }

        $sales = Sale::query()->whereIn('customer_id', $customerIds)->get(['id', 'customer_id', 'sale_date', 'journal_entry_id', 'credit_entry_id', 'receipt_entry_id', 'status']);
        $carriage = SaleDispatch::query()->whereIn('sale_id', $sales->pluck('id'))->whereNotNull('carriage_entry_id')->pluck('sale_id', 'carriage_entry_id');

        /** @var array<int, Sale> $saleOfEntry entry id => the sale it belongs to */
        $saleOfEntry = [];
        foreach ($sales as $sale) {
            $saleOfEntry[$sale->journal_entry_id] = $sale;
            if ($sale->credit_entry_id !== null) {
                $saleOfEntry[$sale->credit_entry_id] = $sale;
            }
        }
        $byId = $sales->keyBy('id');
        foreach ($carriage as $entryId => $saleId) {
            $saleOfEntry[(int) $entryId] = $byId[(int) $saleId];
        }

        /** @var array<int, Sale> $receiptFor receipt entry id => the sale it was taken for */
        $receiptFor = [];
        foreach ($sales as $sale) {
            if ($sale->receipt_entry_id !== null) {
                $receiptFor[$sale->receipt_entry_id] = $sale;
            }
        }
        foreach (SaleReceipt::query()->whereIn('sale_id', $sales->pluck('id'))->pluck('sale_id', 'journal_entry_id') as $entryId => $saleId) {
            $receiptFor[(int) $entryId] = $byId[(int) $saleId];
        }

        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.kiln_id', $this->currentKiln->id())
            ->whereIn('l.party_id', $customerIds)
            ->where('e.status', JournalEntry::STATUS_POSTED)
            ->where('e.type', '!=', JournalType::Reversal->value)
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->get(['l.party_id', 'l.journal_entry_id', 'e.entry_date', 'l.debit', 'l.credit']);

        /** @var array<int, int> $due sale id => paisa it asks for */
        $due = [];
        /** @var array<int, int> $direct sale id => paisa received for that sale */
        $direct = [];
        /** @var array<int, int> $pool customer id => paisa paid or credited, not yet applied */
        $pool = array_fill_keys($customerIds, 0);
        /** @var array<int, list<array{date: string, order: int, sale: int|null, amount: int}>> $charges */
        $charges = array_fill_keys($customerIds, []);

        foreach ($lines as $line) {
            $customerId = (int) $line->party_id;
            $sale = $saleOfEntry[(int) $line->journal_entry_id] ?? null;
            $net = (int) $line->debit - (int) $line->credit;

            // Only the customer's own lines belong to the sale (a carriage entry also has the driver's line).
            if ($sale !== null && $sale->customer_id === $customerId) {
                $due[$sale->id] = ($due[$sale->id] ?? 0) + $net;

                continue;
            }

            $for = $receiptFor[(int) $line->journal_entry_id] ?? null;
            if ($for !== null && $for->customer_id === $customerId && $net < 0) {
                $direct[$for->id] = ($direct[$for->id] ?? 0) - $net;

                continue;
            }

            if ($net > 0) {
                $charges[$customerId][] = ['date' => substr((string) $line->entry_date, 0, 10), 'order' => (int) $line->journal_entry_id, 'sale' => null, 'amount' => $net];
            } else {
                $pool[$customerId] += -$net;
            }
        }

        /** @var array<int, int> $paidDirect sale id => paisa paid by money received for it */
        $paidDirect = [];
        foreach ($sales as $sale) {
            $amount = $due[$sale->id] ?? 0;

            // Money received for this sale pays it first; the rest is the customer's to use elsewhere.
            $paidDirect[$sale->id] = min(max($amount, 0), $direct[$sale->id] ?? 0);
            $pool[$sale->customer_id] += ($direct[$sale->id] ?? 0) - $paidDirect[$sale->id];

            if ($amount - $paidDirect[$sale->id] > 0) {
                $charges[$sale->customer_id][] = ['date' => $sale->sale_date->format('Y-m-d'), 'order' => $sale->journal_entry_id, 'sale' => $sale->id, 'amount' => $amount - $paidDirect[$sale->id]];
            } elseif ($amount < 0) {
                $pool[$sale->customer_id] += -$amount;
            }
        }

        $result = [];
        foreach ($customerIds as $customerId) {
            $list = $charges[$customerId];
            usort($list, fn (array $a, array $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

            foreach ($list as $charge) {
                $paid = min($charge['amount'], $pool[$customerId]);
                $pool[$customerId] -= $paid;

                if ($charge['sale'] !== null) {
                    $result[$charge['sale']] = $paid;
                }
            }
        }

        $out = [];
        foreach ($sales as $sale) {
            $amount = max(0, $due[$sale->id] ?? 0);
            $paid = $paidDirect[$sale->id] + ($result[$sale->id] ?? 0);
            $outstanding = $amount - $paid;

            $out[$sale->id] = [
                'due' => Money::fromPaisa($amount),
                'paid' => Money::fromPaisa($paid),
                'outstanding' => Money::fromPaisa($outstanding),
                'status' => $sale->isCancelled() ? null : match (true) {
                    $outstanding <= 0 => PaymentStatus::Paid,
                    $paid === 0 => PaymentStatus::Unpaid,
                    default => PaymentStatus::PartlyPaid,
                },
            ];
        }

        return [
            'sales' => $out,
            'unapplied' => array_map(fn (int $paisa) => Money::fromPaisa($paisa), $pool),
        ];
    }

    /**
     * @return array{due: Money, paid: Money, outstanding: Money, status: PaymentStatus|null}
     */
    public function forSale(Sale $sale): array
    {
        return $this->forCustomers([$sale->customer_id])['sales'][$sale->id];
    }
}
