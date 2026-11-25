<?php

namespace App\Data\Export;

use App\Accounting\LedgerQueries;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\StageKind;
use App\Labour\WorkerLedger;
use App\Materials\MaterialStock;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\Sale;
use App\Models\User;
use App\Models\Worker;
use App\Production\BrickStock;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The lists that can be downloaded as Excel (client, 2026-11-25), each as one sheet, and all of
 * them together. Amounts are plain numbers in rupees so Excel can add them up.
 */
class DataExporter
{
    /** @return array<string, string> key => label, in menu order */
    public function lists(): array
    {
        return [
            'parties' => __('Parties'),
            'workers' => __('Workers'),
            'sales' => __('Sales'),
            'purchases' => __('Material purchases'),
            'transactions' => __('Transactions'),
            'material-stock' => __('Material stock'),
            'brick-stock' => __('Brick stock'),
        ];
    }

    /** Who may download a list: whoever can see it; "all" needs the data permission. */
    public function allowed(User $actor, string $key): bool
    {
        $permission = match ($key) {
            'all' => Permission::DataManage,
            'parties' => Permission::PartiesView,
            'workers' => Permission::LabourView,
            'sales' => Permission::SalesView,
            'purchases', 'material-stock' => Permission::MaterialsView,
            'transactions' => Permission::AccountsView,
            'brick-stock' => Permission::ProductionView,
            default => null,
        };

        return $permission !== null && $actor->can($permission->value);
    }

    /**
     * The sheets of a download.
     *
     * @return array<string, array{headers: list<string>, rows: list<list<scalar|null>>}>
     */
    public function sheets(string $key, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $keys = $key === 'all' ? array_keys($this->lists()) : [$key];
        $sheets = [];
        foreach ($keys as $k) {
            $sheets[$this->lists()[$k]] = match ($k) {
                'parties' => $this->parties(),
                'workers' => $this->workers(),
                'sales' => $this->sales($from, $to),
                'purchases' => $this->purchases($from, $to),
                'transactions' => $this->transactions($from, $to),
                'material-stock' => $this->materialStock(),
                'brick-stock' => $this->brickStock(),
                default => throw new InvalidArgumentException("Unknown export {$k}."),
            };
        }

        return $sheets;
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} */
    private function parties(): array
    {
        $parties = Party::query()->with('area')->orderBy('name')->get();
        $balances = app(LedgerQueries::class)->partyBalances($parties->pluck('id')->all());
        $roles = DB::table('party_roles')->whereIn('party_id', $parties->pluck('id'))->get()->groupBy('party_id');

        return [
            'headers' => [__('Name'), __('Name (Urdu)'), __('Type'), __('Phone'), __('Phone 2'), __('Address'), __('Area'), __('Balance (Rs)'), __('Who owes'), __('Active')],
            'rows' => $parties->map(function (Party $p) use ($balances, $roles): array {
                $balance = $balances[$p->id] ?? Money::zero();

                return [
                    $p->name, $p->name_ur,
                    collect($roles->get($p->id, []))->map(fn ($r) => PartyRole::from($r->role)->label())->implode(', '),
                    $p->phone, $p->phone_2, $p->address, $p->area?->displayName(),
                    self::rupees($balance->abs()),
                    $balance->isZero() ? '' : ($balance->isPositive() ? __('They owe us') : __('We owe them')),
                    $p->is_active ? __('Yes') : __('No'),
                ];
            })->all(),
        ];
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} */
    private function workers(): array
    {
        $workers = Worker::query()->with('category')->orderBy('name')->get();
        $balances = app(WorkerLedger::class)->balances($workers->pluck('id')->all());

        return [
            'headers' => [__('Name'), __('Name (Urdu)'), __('Category'), __('Phone'), __('Joined on'), __('Balance (Rs)'), __('Who owes'), __('Active')],
            'rows' => $workers->map(function (Worker $w) use ($balances): array {
                $balance = $balances[$w->id];

                return [
                    $w->name, $w->name_ur, $w->category->displayName(), $w->phone, $w->joined_on?->format('d-m-Y'),
                    self::rupees($balance->abs()),
                    $balance->isZero() ? '' : ($balance->isPositive() ? __('Kiln owes worker') : __('Worker owes kiln')),
                    $w->is_active ? __('Yes') : __('No'),
                ];
            })->all(),
        ];
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} */
    private function sales(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $sales = Sale::query()->with('customer')
            ->when($from, fn ($q) => $q->where('sale_date', '>=', $from->format('Y-m-d')))
            ->when($to, fn ($q) => $q->where('sale_date', '<=', $to->format('Y-m-d')))
            ->orderBy('sale_date')->orderBy('id')->get();

        return [
            'headers' => [__('Number'), __('Date'), __('Customer'), __('Total (Rs)'), __('Status'), __('Note')],
            'rows' => $sales->map(fn (Sale $s) => [$s->number, $s->sale_date->format('d-m-Y'), $s->customer->displayName(), self::rupees($s->total), $s->status->label(), $s->notes])->all(),
        ];
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} */
    private function purchases(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $purchases = MaterialPurchase::query()->with(['supplier', 'entry', 'lines.material.unit'])
            ->when($from, fn ($q) => $q->where('purchase_date', '>=', $from->format('Y-m-d')))
            ->when($to, fn ($q) => $q->where('purchase_date', '<=', $to->format('Y-m-d')))
            ->orderBy('purchase_date')->orderBy('id')->get();

        return [
            'headers' => [__('Number'), __('Date'), __('Supplier'), __('Materials'), __('Goods (Rs)'), __('Charges (Rs)'), __('Total (Rs)'), __('Reversed')],
            'rows' => $purchases->map(fn (MaterialPurchase $p) => [
                $p->entry->number, $p->purchase_date->format('d-m-Y'), $p->supplier?->displayName() ?? __('Cash purchase'),
                $p->lines->map(fn ($l) => $l->material->displayName().' '.$l->quantity->format($l->material->unit->decimals ?? 3))->implode(', '),
                self::rupees($p->goods_total), self::rupees($p->charges_total), self::rupees($p->total),
                $p->entry->isReversed() ? __('Yes') : '',
            ])->all(),
        ];
    }

    /**
     * Every line of the journal: the full books.
     *
     * @return array{headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function transactions(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $lines = JournalLine::query()->with(['entry', 'account', 'party', 'worker'])
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from->format('Y-m-d')))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to->format('Y-m-d')))
            ->orderBy('entry_date')->orderBy('journal_entry_id')->orderBy('line_no')->get();

        return [
            'headers' => [__('Date'), __('Number'), __('Type'), __('Account'), __('Party or worker'), __('Debit (Rs)'), __('Credit (Rs)'), __('Note'), __('Reversed')],
            'rows' => $lines->map(fn (JournalLine $l) => [
                $l->entry_date->format('d-m-Y'), $l->entry->number, $l->entry->type->label(), $l->account->displayName(),
                $l->party?->displayName() ?? $l->worker?->displayName(),
                $l->debit->isZero() ? null : self::rupees($l->debit), $l->credit->isZero() ? null : self::rupees($l->credit),
                $l->memo ?? $l->entry->memo, $l->entry->isReversed() ? __('Yes') : '',
            ])->all(),
        ];
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} */
    private function materialStock(): array
    {
        $materials = Material::query()->with('unit')->orderBy('sort_order')->orderBy('name')->get();
        $stock = app(MaterialStock::class);
        $levels = $stock->levels($materials->pluck('id')->all());

        return [
            'headers' => [__('Material'), __('Unit'), __('Quantity'), __('Value (Rs)'), __('Average cost (Rs)')],
            'rows' => $materials->map(function (Material $m) use ($levels, $stock): array {
                $level = $levels[$m->id];
                $average = $stock->averageCost($level);

                return [$m->displayName(), $m->unit->symbol ?? $m->unit->displayName(), (float) $level['quantity']->toDecimal(), self::rupees($level['value']), $average !== null ? self::rupees($average) : null];
            })->all(),
        ];
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} */
    private function brickStock(): array
    {
        $levels = app(BrickStock::class)->levels();
        $stages = ProductionStage::query()->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);
        $name = fn (StageKind $k) => $stages->get($k->value)?->displayName() ?? $k->defaultName();

        $rows = [];
        foreach ([StageKind::Raw, StageKind::InFiring, StageKind::FiredAwaitingUnloading, StageKind::AwaitingRefire] as $kind) {
            $rows[] = [$name($kind), null, (float) $levels[$kind->value]->toDecimal()];
        }
        $products = Product::query()->whereIn('id', array_keys($levels['saleable']))->get()->keyBy('id');
        foreach ($levels['saleable'] as $productId => $quantity) {
            $rows[] = [$name(StageKind::Saleable), $products->get($productId)?->displayName(), (float) $quantity->toDecimal()];
        }

        return ['headers' => [__('Stage'), __('Grade'), __('Quantity')], 'rows' => $rows];
    }

    private static function rupees(Money $money): float
    {
        return $money->paisa / 100;
    }
}
