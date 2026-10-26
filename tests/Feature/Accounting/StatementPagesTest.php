<?php

use App\Accounting\LedgerQueries;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Labour\WorkerLedger;
use App\Livewire\Accounts\CashBook;
use App\Livewire\Parties\PartyStatement;
use Livewire\Livewire;

/*
| Long statements are shown a page at a time (M11). Every page must carry the right balance
| brought forward and running balances, exactly as the whole statement in one piece would.
*/

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
    $this->customer = makeParty($this->owner, 'Demo Customer', opening: '1000');
    $this->from = now()->subDays(10)->startOfDay();
    $this->to = now()->startOfDay();

    // Seven receipts over seven days: 100, 200, … 700.
    foreach (range(1, 7) as $i) {
        recordVoucher($this->owner, JournalType::Receipt, $this->cash, [[$this->customer, (string) ($i * 100)]], ['entry_date' => now()->subDays(8 - $i)->format('Y-m-d')]);
    }
});

it('splits a statement into pages whose balances match the whole statement', function () {
    $ledger = app(LedgerQueries::class);
    $whole = $ledger->partyStatement($this->customer, $this->from, $this->to);
    $balances = array_map(fn (array $row) => $row['balance']->toDecimal(), $whole['rows']);

    $pages = [];
    foreach ([1, 2, 3] as $page) {
        $pages[$page] = $ledger->partyStatement($this->customer, $this->from, $this->to, $page, 3);
    }

    expect($pages[1]['pages'])->toBe(3)->and($pages[1]['count'])->toBe(7)
        ->and(array_map(fn ($p) => count($p['rows']), $pages))->toBe([1 => 3, 2 => 3, 3 => 1])
        ->and(array_merge(...array_map(fn ($p) => array_map(fn (array $row) => $row['balance']->toDecimal(), $p['rows']), array_values($pages))))->toBe($balances)
        ->and($pages[1]['brought_forward']->toDecimal())->toBe($whole['opening']->toDecimal())
        ->and($pages[2]['brought_forward']->toDecimal())->toBe($balances[2])
        ->and($pages[3]['brought_forward']->toDecimal())->toBe($balances[5]);

    // Totals and the closing balance are always those of the whole period.
    foreach ($pages as $page) {
        expect($page['closing']->toDecimal())->toBe($whole['closing']->toDecimal())
            ->and($page['total_credit']->toDecimal())->toBe('2800.00');
    }

    // No page asked for: the newest. A page past the end: the last one.
    expect($ledger->partyStatement($this->customer, $this->from, $this->to, null, 3)['page'])->toBe(3)
        ->and($ledger->partyStatement($this->customer, $this->from, $this->to, 9, 3)['page'])->toBe(3);
});

it('pages the cash book and a worker account the same way', function () {
    $ledger = app(LedgerQueries::class);
    $this->cash->load('ledgerAccount');
    $whole = $ledger->statement($this->cash->ledgerAccount, $this->from, $this->to);
    $paged = $ledger->statement($this->cash->ledgerAccount, $this->from, $this->to, 2, 4);

    expect($paged['rows'][0]['balance']->toDecimal())->toBe($whole['rows'][4]['balance']->toDecimal())
        ->and($paged['brought_forward']->toDecimal())->toBe($whole['rows'][3]['balance']->toDecimal())
        ->and($paged['total_in']->toDecimal())->toBe($whole['total_in']->toDecimal());

    setLabourRate($this->owner, 'daily', '1000', now()->subDays(20)->format('Y-m-d'));
    $worker = makeWorker($this->owner, 'Daily one', 'daily');
    foreach (range(1, 5) as $i) {
        recordWork($this->owner, $worker, '1', now()->subDays(6 - $i)->format('Y-m-d'));
    }
    $workerPage = app(WorkerLedger::class)->statement($worker, $this->from, $this->to, 1, 2);
    expect($workerPage['pages'])->toBe(3)->and($workerPage['rows'][1]['balance']->toDecimal())->toBe('2000.00')
        ->and($workerPage['closing']->toDecimal())->toBe('5000.00');
});

it('shows the newest page first on screen, with older and newer pages', function () {
    config(['app.statement_rows' => 3]);

    $component = Livewire::test(PartyStatement::class, ['party' => $this->customer])
        ->set('from', $this->from->format('Y-m-d'))
        ->assertSee('Rows 7–7 of 7')->assertSee('Page 3 of 3')
        ->assertSeeHtml('wire:click="showStatementPage(2)"');

    $component->call('showStatementPage', 1)
        ->assertSet('statementPage', '1')
        ->assertSee('Rows 1–3 of 7')->assertSee('Page 1 of 3');

    // New dates go back to the newest page.
    $component->set('to', $this->to->format('Y-m-d'))->assertSet('statementPage', '')->assertSee('Page 3 of 3');

    Livewire::test(CashBook::class, ['account' => $this->cash])
        ->set('from', $this->from->format('Y-m-d'))
        ->assertSee('Balance brought forward')->assertSee('Page 3 of 3');
});
