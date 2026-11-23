<?php

namespace App\Sales;

use App\Accounting\LedgerQueries;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Models\Party;
use App\Models\SaleDispatch;
use App\Models\Trolley;
use App\Models\TrolleyCost;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A month for each driver, read from the ledger so that it always agrees with the driver's
 * statement: brought forward + carriage − diesel − maintenance − other costs − paid = closing.
 * A reversal counts in the month it was made, under the kind of entry it reverses.
 */
class DriverSettlementSheet
{
    /**
     * @return list<array{driver: Party, trolleys: string, trips: int, brought_forward: Money, carriage: Money, diesel: Money, maintenance: Money, other: Money, paid: Money, closing: Money, payable: Money}>
     */
    public function forMonth(CarbonInterface $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();

        $drivers = Party::query()->withRole(PartyRole::Driver)->orderBy('name')->get();
        $ids = $drivers->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $ledger = app(LedgerQueries::class);
        $before = $ledger->partyBalances($ids, $from->copy()->subDay());
        $atEnd = $ledger->partyBalances($ids, $to);
        $now = $ledger->partyBalances($ids);

        $moves = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('journal_entries as o', 'o.id', '=', 'e.reversal_of_id')
            ->leftJoin('trolley_costs as c', DB::raw('c.journal_entry_id'), '=', DB::raw('COALESCE(e.reversal_of_id, e.id)'))
            ->whereIn('l.party_id', $ids)
            ->whereBetween('l.entry_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('l.party_id', DB::raw('COALESCE(o.type, e.type)'), 'c.kind')
            ->selectRaw('l.party_id, COALESCE(o.type, e.type) AS type, c.kind, SUM(l.credit) - SUM(l.debit) AS net')
            ->get()
            ->groupBy('party_id');

        $trips = SaleDispatch::query()
            ->whereNull('reversed_at')
            ->whereNotNull('driver_id')
            ->whereBetween('dispatch_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('driver_id, COUNT(*) AS trips')
            ->groupBy('driver_id')
            ->pluck('trips', 'driver_id');

        $trolleys = Trolley::query()->where('is_active', true)->orderBy('number')->get()->groupBy('driver_id');

        $rows = [];
        foreach ($drivers as $driver) {
            $carriage = $diesel = $maintenance = $other = $paid = 0;
            foreach ($moves->get($driver->id, collect()) as $move) {
                $net = (int) $move->net;
                if ($move->type === JournalType::Carriage->value) {
                    $carriage += $net;
                } elseif ($move->type === JournalType::TrolleyCost->value) {
                    match ($move->kind) {
                        TrolleyCost::DIESEL => $diesel -= $net,
                        TrolleyCost::MAINTENANCE => $maintenance -= $net,
                        default => $other -= $net,
                    };
                } else {
                    $paid -= $net;
                }
            }

            // Balances are positive when they owe the kiln; this sheet shows what the kiln owes.
            $broughtForward = $before[$driver->id]->negate();
            $closing = $atEnd[$driver->id]->negate();
            $owedNow = $now[$driver->id]->negate();
            $own = $trolleys->get($driver->id, collect());
            $tripCount = (int) ($trips[$driver->id] ?? 0);

            if ($own->isEmpty() && $tripCount === 0 && $broughtForward->isZero() && $closing->isZero() && ! $moves->has($driver->id)) {
                continue;
            }

            $rows[] = [
                'driver' => $driver,
                'trolleys' => $own->pluck('number')->implode(', '),
                'trips' => $tripCount,
                'brought_forward' => $broughtForward,
                'carriage' => Money::fromPaisa($carriage),
                'diesel' => Money::fromPaisa($diesel),
                'maintenance' => Money::fromPaisa($maintenance),
                'other' => Money::fromPaisa($other),
                'paid' => Money::fromPaisa($paid),
                'closing' => $closing,
                // Never pay more than is still owed today (a later payment may have settled it).
                'payable' => Money::fromPaisa(max(0, min($closing->paisa, $owedNow->paisa))),
            ];
        }

        return $rows;
    }
}
