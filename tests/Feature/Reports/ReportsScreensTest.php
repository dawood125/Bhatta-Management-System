<?php

use App\Actions\Accounting\CloseDay;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Livewire\Reports\BalanceSheet;
use App\Livewire\Reports\DayCloseIndex;
use App\Livewire\Reports\ProfitAndLoss;
use App\Livewire\Sales\SaleForm;
use App\Models\DayClose;
use App\Support\ChartScale;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
    $this->customer = makeParty($this->owner, 'Demo Customer');
    makeSaleableStock($this->owner, ['awal' => '8000']);
    $sale = recordSale($this->owner, $this->customer, ['awal' => ['2000', '15000']]);
    recordDispatch($this->owner, $sale, ['awal' => '2000']);
    recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, '10000']]);
});

describe('access', function () {
    it('opens each report for those who may see it', function (Role $role, array $allowed) {
        $user = makeUser($this->kiln, $role);
        $pages = [
            'reports' => '/reports', 'pnl' => '/reports/profit-and-loss', 'balance' => '/reports/balance-sheet', 'day' => '/reports/day-close',
            'sales' => '/reports/sales', 'production' => '/reports/production', 'costs' => '/reports/costs', 'receivables' => '/reports/receivables',
            'pdf' => '/reports/profit-and-loss.pdf',
        ];

        foreach ($pages as $key => $url) {
            $this->actingAs($user)->get($url)->assertStatus(in_array($key, $allowed, true) ? 200 : 403);
        }
    })->with([
        'owner' => [Role::Owner, ['reports', 'pnl', 'balance', 'day', 'sales', 'production', 'costs', 'receivables', 'pdf']],
        'manager' => [Role::Manager, ['reports', 'pnl', 'balance', 'day', 'sales', 'production', 'costs', 'receivables', 'pdf']],
        'munshi' => [Role::Munshi, ['reports', 'day', 'sales', 'production', 'receivables']],
        'data entry' => [Role::DataEntry, ['reports', 'production']],
        'viewer' => [Role::Viewer, ['reports', 'sales', 'production', 'receivables']],
    ]);

    it('lists only the reports a user may open, and puts Reports and Day close in the menu', function () {
        $this->actingAs(makeUser($this->kiln, Role::DataEntry))->get('/reports')->assertOk()
            ->assertSee('Production summary')->assertDontSee('Profit and loss')->assertDontSee('Sales summary');

        $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee(route('reports.index'), false)->assertSee(route('reports.day-close'), false);
        $this->actingAs(makeUser($this->kiln, Role::Viewer))->get('/dashboard')->assertOk()
            ->assertSee(route('reports.index'), false)->assertDontSee(route('reports.day-close'), false);
    });
});

describe('dashboard', function () {
    it('shows each role only its own figures', function () {
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee('Cash and bank')->assertSeeText('Rs 110,000.00')
            ->assertSee('Others owe us')->assertSeeText('Rs 20,000.00')
            ->assertSee('Income and costs, last six months')->assertSee('Today is not closed yet.');

        $this->actingAs(makeUser($this->kiln, Role::DataEntry))->get('/dashboard')->assertOk()
            ->assertSee('Pathai accepted')->assertSee('Ready for sale')
            ->assertDontSee('Cash and bank')->assertDontSee('Income and costs, last six months')->assertDontSee('Today is not closed yet.');
    });

    it('rounds the chart axis to clean numbers that fit the data', function () {
        expect(ChartScale::ticks(10_900_000))->toBe([0, 5_000_000, 10_000_000, 15_000_000])
            ->and(ChartScale::ticks(0))->toBe([0])
            ->and(ChartScale::label(5_000_000))->toBe('50,000');
    });
});

describe('statements', function () {
    it('shows the profit and loss and the balance sheet, also as PDF in Urdu', function () {
        Livewire::test(ProfitAndLoss::class)->assertSee('Brick sales')->assertSee('30,000.00')->assertSee('Profit');
        Livewire::test(BalanceSheet::class)->assertSee('Balanced: what the kiln owns equals what it owes plus what it is worth.')->assertSee('Customers and others who owe us');

        $this->owner->forceFill(['locale' => 'ur'])->save();
        $pdf = $this->actingAs($this->owner)->get('/reports/balance-sheet.pdf?date='.now()->format('Y-m-d'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
    });
});

describe('day close', function () {
    it('lets the munshi count and close the day, then shows the closed day and refuses entries on it', function () {
        $munshi = actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);

        Livewire::test(DayCloseIndex::class)
            ->assertSee('Cash')
            ->set('counts.'.$this->cash->id, '109500')
            ->assertSee('-500.00')
            ->call('confirmClose')
            ->assertSet('showConfirm', true)
            ->call('closeDay')
            ->assertHasNoErrors()
            ->assertSee('This day was closed by');

        expect(DayClose::query()->sole()->counts()->sole()->difference()->toDecimal())->toBe('-500.00');

        // A closed-day refusal reaches the person as an error toast.
        Livewire::test(SaleForm::class)
            ->set('form.customer_id', (string) $this->customer->id)
            ->set('form.lines.0.product_id', (string) product('awal')->id)
            ->set('form.lines.0.quantity', '100')
            ->set('form.lines.0.rate', '15000')
            ->call('save')
            ->assertDispatched('toast', type: 'error', message: 'The books are closed up to '.now()->format('d-m-Y').'. Only the owner can add entries on or before that day.');
    });

    it('lets only the owner reopen the last closed day', function () {
        app(CloseDay::class)->handle($this->owner, ['close_date' => now()->format('Y-m-d')]);
        $close = DayClose::query()->sole();

        actingInKiln(makeUser($this->kiln, Role::Manager), $this->kiln);
        Livewire::test(DayCloseIndex::class)->assertDontSeeHtml('wire:click="confirmReopen')->call('confirmReopen', $close->id)->assertForbidden();

        actingInKiln($this->owner, $this->kiln);
        Livewire::test(DayCloseIndex::class)
            ->call('confirmReopen', $close->id)
            ->set('reopenForm.reason', 'A late receipt')
            ->call('reopen')
            ->assertHasNoErrors();

        expect($close->fresh()->isReopened())->toBeTrue();
    });
});
