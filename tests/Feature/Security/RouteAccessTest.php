<?php

use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| The permissions review (M11): every page of the app, the one permission it needs, and proof
| that the page checks exactly that permission. A new page must be added to this map, so it
| cannot ship without a decision on who may open it.
*/

/** Pages that every signed-in user may open. */
const OPEN_TO_ALL = ['dashboard', 'profile.edit', 'password.confirm', 'password.confirmation'];

/** Pages that need one of several permissions (they show what the user may see). */
const ANY_OF = [
    'reports.index' => [Permission::FinancialReportsView, Permission::DaysClose, Permission::AccountsView, Permission::PartiesView, Permission::SalesView, Permission::ProductionView, Permission::MaterialsView, Permission::LabourView],
    'settings.index' => [Permission::KilnProfileManage, Permission::SettingsManage],
];

/** @return array<string, Permission> route name => the permission it needs */
function routePermissions(): array
{
    return [
        'users.index' => Permission::UsersView,
        'activity.index' => Permission::ActivityView,
        'parties.index' => Permission::PartiesView,
        'parties.show' => Permission::PartiesView,
        'parties.statement.pdf' => Permission::PartiesView,
        'vouchers.index' => Permission::AccountsView,
        'vouchers.create' => Permission::PaymentsRecord,
        'vouchers.show' => Permission::AccountsView,
        'expenses.index' => Permission::AccountsView,
        'sales.index' => Permission::SalesView,
        'sales.create' => Permission::SalesRecord,
        'sales.deliveries' => Permission::SalesView,
        'sales.drivers' => Permission::SalesView,
        'sales.emails' => Permission::SalesView,
        'sales.show' => Permission::SalesView,
        'sales.invoice' => Permission::SalesView,
        'production.index' => Permission::ProductionView,
        'production.day' => Permission::ProductionView,
        'production.entries' => Permission::ProductionView,
        'materials.index' => Permission::MaterialsView,
        'materials.purchases' => Permission::MaterialsView,
        'materials.purchases.create' => Permission::MaterialsRecord,
        'materials.purchases.show' => Permission::MaterialsView,
        'materials.usage' => Permission::MaterialsView,
        'materials.report' => Permission::MaterialsView,
        'materials.show' => Permission::MaterialsView,
        'labour.index' => Permission::LabourView,
        'labour.workers.show' => Permission::LabourView,
        'labour.work' => Permission::LabourView,
        'labour.attendance' => Permission::LabourView,
        'labour.settlements' => Permission::LabourView,
        'labour.settlements.create' => Permission::LabourPay,
        'labour.settlements.show' => Permission::LabourView,
        'labour.rates' => Permission::LabourView,
        'accounts.index' => Permission::AccountsView,
        'accounts.book' => Permission::AccountsView,
        'transactions.index' => Permission::AccountsView,
        'reports.profit-and-loss' => Permission::FinancialReportsView,
        'reports.balance-sheet' => Permission::FinancialReportsView,
        'reports.trial-balance' => Permission::FinancialReportsView,
        'reports.day-close' => Permission::DaysClose,
        'reports.sales' => Permission::SalesView,
        'reports.production' => Permission::ProductionView,
        'reports.costs' => Permission::FinancialReportsView,
        'reports.receivables' => Permission::PartiesView,
        'reports.pdf' => Permission::FinancialReportsView,
        'settings.kiln' => Permission::KilnProfileManage,
        'settings.email' => Permission::KilnProfileManage,
        'settings.products' => Permission::SettingsManage,
        'settings.units' => Permission::SettingsManage,
        'settings.stages' => Permission::SettingsManage,
        'settings.areas' => Permission::SettingsManage,
        'settings.expense-categories' => Permission::SettingsManage,
        'settings.materials' => Permission::SettingsManage,
        'backups.index' => Permission::BackupsManage,
        'backups.download' => Permission::BackupsManage,
    ];
}

/** A user of the kiln with exactly these permissions and no role. */
function userWith(Kiln $kiln, array $permissions): User
{
    $user = User::factory()->create();
    $user->kilns()->attach($kiln->id);
    app(CurrentKiln::class)->run($kiln, function () use ($user, $permissions): void {
        $user->syncPermissions(array_map(fn (Permission $p) => $p->value, $permissions));
        $user->unsetRelation('permissions');
    });

    return $user;
}

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);

    $cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
    $customer = makeParty($this->owner, 'Demo Customer');
    makeSaleableStock($this->owner, ['awal' => '1000']);
    $sale = recordSale($this->owner, $customer, ['awal' => ['500', '15000']]);
    recordDispatch($this->owner, $sale, ['awal' => '200']);
    $voucher = recordVoucher($this->owner, JournalType::Receipt, $cash, [[$customer, '1000']]);
    $purchase = recordPurchase($this->owner, makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]), [[material('coal'), '2', '30000']]);
    setLabourRate($this->owner, 'daily', '1500');
    $worker = makeWorker($this->owner, 'Daily one', 'daily');
    recordWork($this->owner, $worker, '2');
    $settlement = settleWages($this->owner, $cash, [$worker->id => '3000']);
    recordPathai($this->owner, '500');
    Storage::fake('backups');
    $backup = fakeBackup(now()->format('Y-m-d-H-i-s'));

    $this->params = [
        'parties.show' => ['party' => $customer->id],
        'parties.statement.pdf' => ['party' => $customer->id],
        'vouchers.create' => ['kind' => 'payment'],
        'vouchers.show' => ['entry' => $voucher->id],
        'sales.show' => ['sale' => $sale->id],
        'sales.invoice' => ['sale' => $sale->id],
        'materials.purchases.show' => ['purchase' => $purchase->id],
        'materials.show' => ['material' => material('coal')->id],
        'labour.workers.show' => ['worker' => $worker->id],
        'labour.settlements.show' => ['settlement' => $settlement->id],
        'accounts.book' => ['account' => $cash->id],
        'reports.pdf' => ['report' => 'profit-and-loss'],
        'backups.download' => ['disk' => 'backups', 'file' => $backup],
    ];
});

it('has every page of the app in the access map', function () {
    $mapped = array_merge(array_keys(routePermissions()), array_keys(ANY_OF), OPEN_TO_ALL);
    $pages = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true) && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && Str::startsWith($m, 'auth')))
        ->map(fn ($route) => $route->getName())
        ->reject(fn (?string $name) => $name === null || Str::startsWith($name, ['dev.', 'livewire.']))
        ->values()->all();

    expect(array_values(array_diff($pages, $mapped)))->toBe([], 'Add these pages to the access map')
        ->and(array_values(array_diff($mapped, $pages)))->toBe([], 'These mapped pages no longer exist');
});

it('opens each page with only its permission, and refuses it without', function () {
    $failures = [];
    foreach (routePermissions() as $name => $permission) {
        $url = route($name, $this->params[$name] ?? []);
        $only = $this->actingAs(userWith($this->kiln, [$permission]))->get($url)->getStatusCode();
        $without = $this->actingAs(userWith($this->kiln, array_values(array_filter(Permission::cases(), fn (Permission $p) => $p !== $permission))))->get($url)->getStatusCode();

        if ($only !== 200 || $without !== 403) {
            $failures[] = "{$name}: with only {$permission->value} → {$only}, with everything else → {$without}";
        }
    }

    foreach (ANY_OF as $name => $permissions) {
        foreach ($permissions as $permission) {
            $status = $this->actingAs(userWith($this->kiln, [$permission]))->get(route($name))->getStatusCode();
            if (! in_array($status, [200, 302], true)) {
                $failures[] = "{$name}: with only {$permission->value} → {$status}";
            }
        }
        $others = array_values(array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, $permissions, true)));
        $status = $this->actingAs(userWith($this->kiln, $others))->get(route($name))->getStatusCode();
        if ($status !== 403) {
            $failures[] = "{$name}: without any of its permissions → {$status}";
        }
    }

    expect($failures)->toBe([]);
});

it('opens the dashboard and profile for a user with no permissions at all, and sends guests to the login', function () {
    $nobody = userWith($this->kiln, []);
    $this->actingAs($nobody)->get('/dashboard')->assertOk();
    $this->actingAs($nobody)->get('/profile')->assertOk();

    auth()->logout();
    foreach (array_keys(routePermissions()) as $name) {
        $this->get(route($name, $this->params[$name] ?? []))->assertRedirect(route('login'));
    }
});

it('shows each role only links it may open', function (Role $role) {
    $user = makeUser($this->kiln, $role);
    $base = rtrim(config('app.url'), '/');
    $queue = [['/dashboard', 'start']];
    $seen = [];
    $failures = [];

    while ($queue !== [] && count($seen) < 200) {
        [$path, $from] = array_shift($queue);
        $key = parse_url($path, PHP_URL_PATH);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $response = $this->actingAs($user)->get($path);
        if (! in_array($response->getStatusCode(), [200, 302], true)) {
            $failures[] = "{$path} → {$response->getStatusCode()} (linked from {$from})";

            continue;
        }
        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            continue;
        }

        preg_match_all('/href="([^"#]+)"/', (string) $response->getContent(), $matches);
        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href);
            if (str_starts_with($href, $base)) {
                $href = substr($href, strlen($base)) ?: '/';
            }
            if (str_starts_with($href, '/') && ! str_starts_with($href, '//') && ! preg_match('#^/(build|livewire|storage|favicon)#', $href)) {
                $queue[] = [$href, $path];
            }
        }
    }

    expect($failures)->toBe([])->and(count($seen))->toBeGreaterThan(1);
})->with([Role::Owner, Role::Manager, Role::Munshi, Role::DataEntry, Role::Viewer]);
