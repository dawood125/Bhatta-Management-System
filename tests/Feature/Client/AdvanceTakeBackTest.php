<?php

use App\Accounting\LedgerVerifier;
use App\Actions\Labour\RecordSettlement;
use App\Enums\Role;
use App\Livewire\Labour\SettlementForm;
use App\Models\Settlement;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Client, 2026-11-25: at a settlement the owner chooses how much of a worker's advance to take
 * back from his wages (all, half or none); the rest of the advance stays for later.
 */
beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);

    $this->cash = makeTreasuryAccount($this->owner, 'Cash box', '100000');
    setLabourRate($this->owner, 'pathai', '1000');
    $this->worker = makeWorker($this->owner, 'Pathai family', 'pathai');
});

/** Settle one worker for the last 7 days; take_back left out means "all". */
function settleWith(User $owner, TreasuryAccount $cash, Worker $worker, string $paid, ?string $takeBack = null): Settlement
{
    $line = ['worker_id' => (string) $worker->id, 'paid' => $paid] + ($takeBack !== null ? ['take_back' => $takeBack] : []);

    return app(RecordSettlement::class)->handle($owner, [
        'period_from' => now()->subDays(6)->format('Y-m-d'),
        'period_to' => now()->format('Y-m-d'),
        'settlement_date' => now()->format('Y-m-d'),
        'account_id' => (string) $cash->id,
        'notes' => '',
        'lines' => [$line],
    ], (string) Str::uuid());
}

describe('an old advance of Rs 5,000 and Rs 3,000 earned this week', function () {
    beforeEach(function () {
        giveAdvance($this->owner, $this->worker, $this->cash, '5000', now()->subDays(20)->format('Y-m-d'));
        recordWork($this->owner, $this->worker, '3000', now()->subDays(2)->format('Y-m-d'));
    });

    it('takes the whole advance back by default, as before', function () {
        expect(validationErrors(fn () => settleWith($this->owner, $this->cash, $this->worker, '1')))->toHaveKey('lines.0.paid');

        $settlement = settleWith($this->owner, $this->cash, $this->worker, '0');
        $line = $settlement->lines->first();

        expect($line->advance_taken_back->toDecimal())->toBe('3000.00')
            ->and($line->advance_left->toDecimal())->toBe('2000.00')
            ->and(workerBalance($this->worker))->toBe('-2000.00');
    });

    it('pays the full wages when nothing is taken back, and the advance stays', function () {
        $settlement = settleWith($this->owner, $this->cash, $this->worker, '3000', '0');
        $line = $settlement->lines->first();

        expect($settlement->total_paid->toDecimal())->toBe('3000.00')
            ->and($line->advance_taken_back->toDecimal())->toBe('0.00')
            ->and($line->advance_left->toDecimal())->toBe('5000.00')
            ->and(workerBalance($this->worker))->toBe('-5000.00')
            ->and(balanceOf($this->cash))->toBe('92000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('pays half and takes half back', function () {
        $settlement = settleWith($this->owner, $this->cash, $this->worker, '1500', '1500');
        $line = $settlement->lines->first();

        expect($line->advance_taken_back->toDecimal())->toBe('1500.00')
            ->and($line->advance_left->toDecimal())->toBe('3500.00')
            ->and(workerBalance($this->worker))->toBe('-3500.00');
    });

    it('cannot take back more than the wages due', function () {
        expect(validationErrors(fn () => settleWith($this->owner, $this->cash, $this->worker, '0', '4000')))->toHaveKey('lines.0.take_back');
    });

    it('cannot pay more than the wages less what is taken back', function () {
        expect(validationErrors(fn () => settleWith($this->owner, $this->cash, $this->worker, '2000', '1500')))->toHaveKey('lines.0.paid');
    });

    it('leaves the next settlement with the advance still owed', function () {
        settleWith($this->owner, $this->cash, $this->worker, '3000', '0');
        recordWork($this->owner, $this->worker, '4000');

        // Next week: Rs 4,000 earned, Rs 5,000 advance; take Rs 1,000 back and pay Rs 3,000.
        $line = settleWith($this->owner, $this->cash, $this->worker, '3000', '1000')->lines->first();

        expect($line->advance_left->toDecimal())->toBe('4000.00')
            ->and(workerBalance($this->worker))->toBe('-4000.00');
    });

    it('offers All, Half and None on the settlement screen', function () {
        $component = Livewire::test(SettlementForm::class)
            ->assertSee('Take back from advance')
            ->assertSet("takeBack.{$this->worker->id}", '3000.00')
            ->assertSet("pay.{$this->worker->id}", '0.00')
            ->call('setTakeBack', $this->worker->id, 'none')
            ->assertSet("takeBack.{$this->worker->id}", '0.00')
            ->assertSet("pay.{$this->worker->id}", '3000.00')
            ->call('setTakeBack', $this->worker->id, 'half')
            ->assertSet("takeBack.{$this->worker->id}", '1500.00')
            ->assertSet("pay.{$this->worker->id}", '1500.00')
            ->set("takeBack.{$this->worker->id}", '500')
            ->assertSet("pay.{$this->worker->id}", '2500.00')
            ->call('save')
            ->assertHasNoErrors();

        $line = Settlement::query()->latest('id')->firstOrFail()->lines->first();
        expect($line->advance_taken_back->toDecimal())->toBe('500.00')
            ->and($line->paid->toDecimal())->toBe('2500.00')
            ->and($line->advance_left->toDecimal())->toBe('4500.00')
            ->and(workerBalance($this->worker))->toBe('-4500.00');
    });

    it('shows a take-back error on its own field', function () {
        Livewire::test(SettlementForm::class)
            ->set("takeBack.{$this->worker->id}", '4000')
            ->set("pay.{$this->worker->id}", '0')
            ->call('save')
            ->assertHasErrors(["takeBack.{$this->worker->id}"]);
    });

    it('prints the advance taken back and left on the payslip', function () {
        $settlement = settleWith($this->owner, $this->cash, $this->worker, '1500', '1500');

        $this->get(route('labour.settlements.show', $settlement))
            ->assertOk()
            ->assertSee('Advance taken back')
            ->assertSee('3,500.00');
    });
});

it('can take back only the advance when the wages are more', function () {
    giveAdvance($this->owner, $this->worker, $this->cash, '2000', now()->subDays(3)->format('Y-m-d'));
    recordWork($this->owner, $this->worker, '3000', now()->subDays(2)->format('Y-m-d'));

    expect(validationErrors(fn () => settleWith($this->owner, $this->cash, $this->worker, '0', '2500')))->toHaveKey('lines.0.take_back');

    $line = settleWith($this->owner, $this->cash, $this->worker, '2000', '1000')->lines->first();

    expect($line->advance_left->toDecimal())->toBe('1000.00')
        ->and(workerBalance($this->worker))->toBe('-1000.00');
});

it('keeps no advance figures for a worker without an advance', function () {
    recordWork($this->owner, $this->worker, '3000', now()->subDays(2)->format('Y-m-d'));

    $line = settleWith($this->owner, $this->cash, $this->worker, '3000', '500')->lines->first();

    expect($line->advance_taken_back)->toBeNull()
        ->and($line->advance_left)->toBeNull()
        ->and(workerBalance($this->worker))->toBe('0.00');

    Livewire::test(SettlementForm::class)->assertSet('takeBack', []);
});

it('lists Driver settlement in the sidebar', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('sales.driver-settlement'), false);
});
