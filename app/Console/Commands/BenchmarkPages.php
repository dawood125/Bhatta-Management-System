<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Signature('kiln:benchmark {--kiln= : Kiln id (default: the one with the most ledger rows)} {--runs=3 : Times each page is opened}')]
#[Description('Time every main page and report as the kiln owner (read only)')]
class BenchmarkPages extends Command
{
    private int $queries = 0;

    public function handle(Kernel $kernel): int
    {
        DB::listen(function (): void {
            $this->queries++;
        });

        $kilnId = (int) ($this->option('kiln') ?: DB::table('journal_lines')->select('kiln_id')->groupBy('kiln_id')->orderByRaw('COUNT(*) DESC')->value('kiln_id'));
        $kiln = Kiln::query()->find($kilnId);
        if ($kiln === null) {
            $this->components->error('No kiln with ledger rows.');

            return self::FAILURE;
        }

        $owner = app(CurrentKiln::class)->run($kiln, fn () => $kiln->users()
            ->whereHas('roles', fn ($q) => $q->where('name', Role::Owner->value)->where('model_has_roles.kiln_id', $kiln->id))
            ->first());
        if (! $owner instanceof User) {
            $this->components->error('The kiln has no owner.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s: %s ledger rows, %s sales, %s brick movements.', $kiln->name,
            number_format(DB::table('journal_lines')->where('kiln_id', $kiln->id)->count()),
            number_format(DB::table('sales')->where('kiln_id', $kiln->id)->count()),
            number_format(DB::table('brick_movements')->where('kiln_id', $kiln->id)->count())));

        $rows = [];
        foreach ($this->pages($kiln) as $label => $uri) {
            $times = [];
            $queries = 0;
            $status = 0;
            for ($i = 0; $i < max(1, (int) $this->option('runs')); $i++) {
                [$status, $ms, $queries] = $this->open($kernel, $owner, $uri);
                $times[] = $ms;
            }
            sort($times);
            $median = $times[intdiv(count($times), 2)];
            $rows[] = [$label, $status, number_format($median).' ms', $queries, $median > 1000 ? '<fg=red>slow</>' : ($median > 500 ? '<fg=yellow>check</>' : '<fg=green>ok</>')];
        }

        $this->table(['Page', 'Status', 'Time (median)', 'Queries', ''], $rows);

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: float, 2: int} status, milliseconds, queries */
    private function open(Kernel $kernel, User $owner, string $uri): array
    {
        app()->forgetScopedInstances();
        Auth::guard('web')->setUser($owner);
        $this->queries = 0;

        $request = Request::create($uri, 'GET');
        $started = hrtime(true);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $started) / 1e6;
        $queries = $this->queries;
        $kernel->terminate($request, $response);

        return [$response->getStatusCode(), $ms, $queries];
    }

    /** @return array<string, string> */
    private function pages(Kiln $kiln): array
    {
        $customer = DB::table('journal_lines')->where('kiln_id', $kiln->id)->whereNotNull('party_id')->select('party_id')->groupBy('party_id')->orderByRaw('COUNT(*) DESC')->value('party_id');
        $worker = DB::table('journal_lines')->where('kiln_id', $kiln->id)->whereNotNull('worker_id')->select('worker_id')->groupBy('worker_id')->orderByRaw('COUNT(*) DESC')->value('worker_id');
        $cash = DB::table('treasury_accounts')->where('kiln_id', $kiln->id)->orderBy('id')->value('id');
        $sale = DB::table('sales')->where('kiln_id', $kiln->id)->max('id');
        $material = DB::table('materials')->where('kiln_id', $kiln->id)->where('code', 'coal')->value('id');
        $year = 'from='.now()->subYear()->format('Y-m-d').'&to='.now()->format('Y-m-d');

        return array_filter([
            'Dashboard' => '/dashboard',
            'Parties' => '/parties',
            'Party statement (busiest)' => $customer ? "/parties/{$customer}" : null,
            'Party statement, a year' => $customer ? "/parties/{$customer}?{$year}" : null,
            'Party statement PDF, a year' => $customer ? "/parties/{$customer}/statement.pdf?{$year}" : null,
            'Payments and receipts' => '/vouchers',
            'Expenses' => '/expenses',
            'Cash and bank accounts' => '/accounts',
            'Cash book' => $cash ? "/accounts/{$cash}/book" : null,
            'Cash book, a year' => $cash ? "/accounts/{$cash}/book?{$year}" : null,
            'Transactions' => '/transactions',
            'Sales' => '/sales',
            'Sale' => $sale ? "/sales/{$sale}" : null,
            'Invoice PDF' => $sale ? "/sales/{$sale}/invoice" : null,
            'Deliveries' => '/sales/deliveries',
            'Drivers due' => '/sales/drivers',
            'Brick stock' => '/production',
            'Daily production' => '/production/day',
            'Production entries' => '/production/entries',
            'Material stock' => '/materials',
            'Material purchases' => '/materials/purchases',
            'Coal stock card' => $material ? "/materials/{$material}" : null,
            'Material cost report' => '/materials/report',
            'Workers' => '/labour',
            'Worker account (busiest)' => $worker ? "/labour/workers/{$worker}" : null,
            'Work entries' => '/labour/work',
            'Settlements' => '/labour/settlements',
            'Reports' => '/reports',
            'Profit and loss, a year' => "/reports/profit-and-loss?{$year}",
            'Balance sheet' => '/reports/balance-sheet',
            'Balance sheet PDF' => '/reports/balance-sheet.pdf',
            'Trial balance' => '/reports/trial-balance',
            'Day close' => '/reports/day-close',
            'Sales summary, a year' => "/reports/sales?{$year}",
            'Production summary, a year' => "/reports/production?{$year}",
            'Cost per 1,000 bricks, a year' => "/reports/costs?{$year}",
            'Who owes us' => '/reports/receivables',
            'Activity log' => '/activity',
        ]);
    }
}
