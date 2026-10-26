<?php

namespace Database\Seeders;

use App\Actions\Accounting\RecordCapital;
use App\Actions\Accounting\SaveTreasuryAccount;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Labour\RecordSettlement;
use App\Actions\Labour\RecordWorkEntry;
use App\Actions\Labour\SaveLabourRate;
use App\Actions\Labour\SaveWorker;
use App\Actions\Materials\RecordMaterialPurchase;
use App\Actions\Parties\SaveParty;
use App\Actions\Production\RecordFiring;
use App\Actions\Production\RecordLoading;
use App\Actions\Production\RecordNakaasi;
use App\Actions\Production\RecordPathai;
use App\Actions\Sales\RecordDispatch;
use App\Actions\Sales\RecordSale;
use App\Actions\Vouchers\RecordVoucher;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Labour\WorkerLedger;
use App\Models\ExpenseCategory;
use App\Models\Kiln;
use App\Models\LabourCategory;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Worker;
use App\Support\Access\KilnAccess;
use App\Support\Access\PermissionSynchronizer;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\KilnDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Performance data (M11): a separate kiln with years of everyday activity, entered through the
 * real actions, so that pages and reports can be timed at about 100,000 ledger rows.
 *
 *     VOLUME_DAYS=950 php artisan db:seed --class=VolumeSeeder
 *
 * Never on the live server (db:seed is blocked there). Names are made up.
 */
class VolumeSeeder extends Seeder
{
    private User $owner;

    /** Seeded, so every run makes the same data. */
    private Randomizer $random;

    private TreasuryAccount $cash;

    /** @var array<string, int> product code => id */
    private array $products = [];

    /** @var list<Party> */
    private array $customers = [];

    /** @var list<Party> */
    private array $drivers = [];

    private Party $coalSupplier;

    /** @var array<string, list<Worker>> */
    private array $workers = [];

    /** @var array<int, int> customer id => what they still owe, in rupees */
    private array $owed = [];

    private int $owedToSupplier = 0;

    /** @var list<array{sale: Sale, day: int}> */
    private array $toDeliver = [];

    public function run(PermissionSynchronizer $permissions, KilnAccess $access, CurrentKiln $currentKiln, KilnDefaults $defaults): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Never load performance data into the live database.');
        }

        $this->random = new Randomizer(new Mt19937(42));
        DB::disableQueryLog();
        $days = (int) (getenv('VOLUME_DAYS') ?: 950);
        $start = now()->subDays($days)->startOfDay();

        $permissions->sync();
        $kiln = Kiln::query()->create(['name' => 'Volume Test Kiln']);
        $currentKiln->set($kiln);
        $defaults->seed($kiln);
        $this->owner = User::query()->create([
            'name' => 'Volume Owner', 'username' => 'volume-owner-'.Str::lower(Str::random(6)), 'password' => Str::password(32),
        ]);
        $access->grant($this->owner, $kiln, Role::Owner);
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        $this->setUp($start);

        $began = microtime(true);
        for ($day = 0; $day < $days; $day++) {
            $date = $start->copy()->addDays($day);
            DB::transaction(fn () => $this->day($day, $date));

            if ($day % 50 === 49) {
                $this->command->info(sprintf('%d/%d days, %s ledger rows, %.0f s', $day + 1, $days, number_format(DB::table('journal_lines')->where('kiln_id', $kiln->id)->count()), microtime(true) - $began));
            }
        }

        $this->command->info(sprintf('Done: %s journal entries, %s ledger rows, %s sales, %s brick movements for "%s".',
            number_format(DB::table('journal_entries')->where('kiln_id', $kiln->id)->count()),
            number_format(DB::table('journal_lines')->where('kiln_id', $kiln->id)->count()),
            number_format(DB::table('sales')->where('kiln_id', $kiln->id)->count()),
            number_format(DB::table('brick_movements')->where('kiln_id', $kiln->id)->count()),
            $kiln->name,
        ));
    }

    private function setUp(Carbon $start): void
    {
        $opening = $start->copy()->subDay()->format('Y-m-d');
        $this->cash = app(SaveTreasuryAccount::class)->handle($this->owner, null, [
            'kind' => TreasuryKind::Cash->value, 'name' => 'Cash', 'name_ur' => '', 'account_number' => '',
            'opening_balance' => '3000000', 'opening_date' => $opening, 'allow_negative' => false,
        ], (string) Str::uuid());

        $this->products = Product::query()->pluck('id', 'code')->all();

        $party = fn (string $name, string $role) => app(SaveParty::class)->handle($this->owner, null, [
            'roles' => [$role], 'name' => $name, 'name_ur' => '', 'phone' => '', 'phone_2' => '', 'address' => '',
            'area_id' => '', 'sales_agent_id' => '', 'notes' => '', 'opening_amount' => '0',
            'opening_direction' => SaveParty::THEY_OWE_US, 'opening_date' => $opening,
        ], (string) Str::uuid());

        for ($i = 1; $i <= 60; $i++) {
            $this->customers[] = $party(sprintf('Customer %03d', $i), 'customer');
        }
        for ($i = 1; $i <= 8; $i++) {
            $this->drivers[] = $party(sprintf('Driver %02d', $i), 'driver');
        }
        $this->coalSupplier = $party('Coal Supplier', 'supplier');

        $rate = fn (string $code, string $rate) => app(SaveLabourRate::class)->handle($this->owner, [
            'labour_category_id' => (string) LabourCategory::query()->where('code', $code)->value('id'),
            'worker_id' => '', 'effective_from' => $opening, 'rate' => $rate,
        ]);
        $rate('pathai', '1500');
        $rate('kamyaar', '300');
        $rate('nakaasi', '250');
        $rate('daily', '1200');

        foreach (['pathai' => 3, 'kamyaar' => 1, 'nakaasi' => 1, 'daily' => 6] as $code => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $this->workers[$code][] = app(SaveWorker::class)->handle($this->owner, null, [
                    'name' => ucfirst($code).' worker '.$i, 'name_ur' => '', 'phone' => '',
                    'labour_category_id' => (string) LabourCategory::query()->where('code', $code)->value('id'),
                    'joined_on' => '', 'notes' => '',
                ]);
            }
        }
    }

    private function day(int $day, Carbon $date): void
    {
        $d = $date->format('Y-m-d');
        $key = fn () => (string) Str::uuid();

        // Coal every 4 days, on credit; paid at the end of each month.
        if ($day % 4 === 0) {
            app(RecordMaterialPurchase::class)->handle($this->owner, [
                'purchase_date' => $d, 'payment' => MaterialPurchase::ON_CREDIT, 'supplier_id' => (string) $this->coalSupplier->id,
                'account_id' => '', 'reference' => '', 'notes' => '', 'charges' => [],
                'lines' => [['material_id' => (string) Material::query()->where('code', 'coal')->value('id'), 'quantity' => '20', 'rate' => '30000']],
            ], $key());
            $this->owedToSupplier += 600000;
        }

        // Production: three Pathai teams, then loading, firing (with coal) and Nakaasi of the day's bricks.
        $accepted = 0;
        foreach ($this->workers['pathai'] as $team) {
            $made = 9000 + $this->random->getInt(0, 2000);
            $rejected = intdiv($made * $this->random->getInt(1, 3), 100);
            $accepted += $made - $rejected;
            app(RecordPathai::class)->handle($this->owner, [
                'entry_date' => $d, 'team' => '', 'worker_id' => (string) $team->id, 'made' => (string) $made, 'rejected' => (string) $rejected, 'notes' => '',
            ], $key());
        }
        app(RecordLoading::class)->handle($this->owner, ['entry_date' => $d, 'quantity' => (string) $accepted, 'worker_id' => (string) $this->workers['kamyaar'][0]->id, 'notes' => ''], $key());
        app(RecordFiring::class)->handle($this->owner, [
            'entry_date' => $d, 'quantity' => (string) $accepted, 'notes' => '',
            'fuel' => [['material_id' => (string) Material::query()->where('code', 'coal')->value('id'), 'quantity' => '4.5']],
        ], $key());
        $doom = intdiv($accepted * 18, 100);
        $khingar = intdiv($accepted * 5, 100);
        $tooty = intdiv($accepted * 3, 100);
        $grades = ['awal' => $accepted - $doom - $khingar - $tooty, 'doom' => $doom, 'khingar' => $khingar, 'tooty' => $tooty];
        app(RecordNakaasi::class)->handle($this->owner, [
            'entry_date' => $d, 'notes' => '', 'worker_id' => (string) $this->workers['nakaasi'][0]->id,
            'lines' => array_map(fn ($code, $quantity) => ['product_id' => (string) $this->products[$code], 'quantity' => (string) $quantity], array_keys($grades), $grades),
        ], $key());

        // Yesterday's open sales are delivered today.
        $carriage = [];
        foreach ($this->toDeliver as $i => $pending) {
            $driver = $this->pick($this->drivers);
            $sale = $pending['sale'];
            app(RecordDispatch::class)->handle($this->owner, $sale, [
                'dispatch_date' => $d, 'driver_id' => (string) $driver->id, 'vehicle' => '', 'carriage' => '3000', 'notes' => '',
                'lines' => $sale->lines->map(fn ($line) => ['sale_line_id' => (string) $line->id, 'quantity' => str_replace(',', '', $line->quantity->format(0))])->all(),
            ], $key());
            $carriage[$driver->id] = ($carriage[$driver->id] ?? 0) + 3000;
            $this->owed[$sale->customer_id] = ($this->owed[$sale->customer_id] ?? 0) + 3000;
            unset($this->toDeliver[$i]);
        }

        // Eight sales: six delivered and partly paid at once, two delivered tomorrow.
        for ($i = 0; $i < 8; $i++) {
            $customer = $this->pick($this->customers);
            $lines = [
                ['product_id' => (string) $this->products['awal'], 'quantity' => (string) (2000 + 100 * $this->random->getInt(0, 6)), 'rate' => '15000', 'discount' => ''],
                ['product_id' => (string) $this->products['doom'], 'quantity' => (string) (400 + 50 * $this->random->getInt(0, 4)), 'rate' => '12000', 'discount' => ''],
            ];
            $total = (int) array_sum(array_map(fn ($l) => (int) $l['quantity'] * (int) $l['rate'] / 1000, $lines));
            $now = $i < 6;
            $driver = $this->pick($this->drivers);
            $received = $now ? (int) round($total * 0.7, -2) : 0;

            $sale = app(RecordSale::class)->handle($this->owner, [
                'sale_date' => $d, 'customer_id' => (string) $customer->id, 'agent_id' => '', 'notes' => '', 'lines' => $lines,
                'deliver_now' => $now, 'driver_id' => $now ? (string) $driver->id : '', 'vehicle' => '', 'carriage' => $now ? '3000' : '',
                'received' => $now ? (string) $received : '', 'account_id' => $now ? (string) $this->cash->id : '',
            ], $key());

            $this->owed[$customer->id] = ($this->owed[$customer->id] ?? 0) + $total - $received + ($now ? 3000 : 0);
            if ($now) {
                $carriage[$driver->id] = ($carriage[$driver->id] ?? 0) + 3000;
            } else {
                $this->toDeliver[] = ['sale' => $sale->load('lines'), 'day' => $day];
            }
        }

        // Customers who owe the most pay something.
        arsort($this->owed);
        foreach (array_slice($this->owed, 0, 3, true) as $customerId => $amount) {
            $pay = min($amount, 60000);
            if ($pay > 0) {
                app(RecordVoucher::class)->handle($this->owner, JournalType::Receipt, [
                    'account_id' => (string) $this->cash->id, 'entry_date' => $d, 'memo' => '',
                    'lines' => [['type' => RecordVoucher::LINE_PARTY, 'party_id' => (string) $customerId, 'account_id' => '', 'amount' => (string) $pay, 'memo' => '']],
                ], $key());
                $this->owed[$customerId] -= $pay;
            }
        }

        // Drivers get their carriage at the end of the day, in one payment (six sales go out every day).
        app(RecordVoucher::class)->handle($this->owner, JournalType::Payment, [
            'account_id' => (string) $this->cash->id, 'entry_date' => $d, 'memo' => 'Carriage',
            'lines' => array_map(fn ($driverId, $amount) => ['type' => RecordVoucher::LINE_PARTY, 'party_id' => (string) $driverId, 'account_id' => '', 'amount' => (string) $amount, 'memo' => ''], array_keys($carriage), $carriage),
        ], $key());

        // Daily labour and running expenses.
        foreach ($this->workers['daily'] as $worker) {
            app(RecordWorkEntry::class)->handle($this->owner, ['worker_id' => (string) $worker->id, 'work_date' => $d, 'quantity' => '1', 'amount' => '', 'description' => ''], $key());
        }
        $categories = ExpenseCategory::query()->pluck('id')->all();
        for ($i = 0; $i < 4; $i++) {
            app(RecordExpense::class)->handle($this->owner, [
                'account_id' => (string) $this->cash->id, 'entry_date' => $d, 'expense_category_id' => (string) $this->pick($categories),
                'expense_sub_category_id' => '', 'amount' => (string) (1000 * $this->random->getInt(2, 15)), 'description' => '',
            ], $key());
        }

        // Every Saturday the workers are paid what they are owed.
        if ($date->isSaturday()) {
            $workers = array_merge(...array_values($this->workers));
            $owed = app(WorkerLedger::class)->balances(array_map(fn (Worker $w) => $w->id, $workers), $date);
            $lines = [];
            foreach ($owed as $workerId => $amount) {
                if ($amount->isPositive()) {
                    $lines[] = ['worker_id' => (string) $workerId, 'paid' => $amount->toDecimal()];
                }
            }
            if ($lines !== []) {
                app(RecordSettlement::class)->handle($this->owner, [
                    'period_from' => $date->copy()->subDays(6)->format('Y-m-d'), 'period_to' => $d, 'settlement_date' => $d,
                    'account_id' => (string) $this->cash->id, 'notes' => '', 'lines' => $lines,
                ], $key());
            }
        }

        // Month end: pay the coal supplier, and the owner takes out some profit.
        if ($date->isLastOfMonth()) {
            if ($this->owedToSupplier > 0) {
                app(RecordVoucher::class)->handle($this->owner, JournalType::Payment, [
                    'account_id' => (string) $this->cash->id, 'entry_date' => $d, 'memo' => '',
                    'lines' => [['type' => RecordVoucher::LINE_PARTY, 'party_id' => (string) $this->coalSupplier->id, 'account_id' => '', 'amount' => (string) $this->owedToSupplier, 'memo' => '']],
                ], $key());
                $this->owedToSupplier = 0;
            }
            app(RecordCapital::class)->handle($this->owner, [
                'direction' => RecordCapital::OUT, 'account_id' => $this->cash->id, 'amount' => '2000000', 'entry_date' => $d, 'memo' => '',
            ], $key());
        }
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[array_keys($items)[$this->random->getInt(0, count($items) - 1)]];
    }
}
