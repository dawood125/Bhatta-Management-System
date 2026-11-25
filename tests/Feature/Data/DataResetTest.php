<?php

use App\Accounting\LedgerVerifier;
use App\Actions\Labour\SaveWorker;
use App\Backups\BackupRunner;
use App\Data\DataReset;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Livewire\Data\DataIndex;
use App\Materials\MaterialStock;
use App\Models\BackupRun;
use App\Models\JournalEntry;
use App\Models\Kiln;
use App\Models\Material;
use App\Models\Party;
use App\Models\ProductionEntry;
use App\Models\Sale;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Data → Reset (client, 2026-11-25): "Clear all entries" and "Factory reset", owner only,
 * after a backup, with the password and a typed phrase.
 */
beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    Storage::fake('backups');

    // A little of everything.
    $this->cash = makeTreasuryAccount($this->owner, 'Cash box', '50000');
    $this->customer = makeParty($this->owner, 'Customer One', [PartyRole::Customer], '7000');
    $this->supplier = makeParty($this->owner, 'Supplier One', [PartyRole::Supplier]);
    setLabourRate($this->owner, 'pathai', '1000');
    $this->worker = makeWorker($this->owner, 'Pathai family', 'pathai', ['opening_amount' => '2000', 'opening_direction' => SaveWorker::WORKER_OWES, 'opening_date' => now()->format('Y-m-d')]);
    recordPurchase($this->owner, $this->supplier, [[material('coal'), '10', '5000']]);
    recordPathai($this->owner, '5000', '0', ['worker_id' => (string) $this->worker->id]);
    makeSaleableStock($this->owner, ['awal' => '1000']);
    recordSale($this->owner, $this->customer, ['awal' => ['500', '20']]);
    giveAdvance($this->owner, $this->worker, $this->cash, '1000');
});

function resetWith(User $owner, string $mode, string $password = 'password1', string $phrase = DataReset::PHRASE): array
{
    return app(DataReset::class)->handle($owner, ['mode' => $mode, 'password' => $password, 'phrase' => $phrase]);
}

describe('clear all entries', function () {
    it('deletes every entry and sets all balances and stock to 0, keeping lists and settings', function () {
        $materials = Material::query()->count();

        $result = resetWith($this->owner, DataReset::CLEAR_ENTRIES);

        expect($result['backup']->status)->toBe(BackupRun::SUCCEEDED)
            ->and(JournalEntry::query()->count())->toBe(0)
            ->and(Sale::query()->count())->toBe(0)
            ->and(ProductionEntry::query()->count())->toBe(0)
            ->and(DB::table('material_movements')->where('kiln_id', $this->kiln->id)->count())->toBe(0)
            ->and(DB::table('work_entries')->where('kiln_id', $this->kiln->id)->count())->toBe(0)
            // Lists stay, with zero balances.
            ->and(Party::query()->count())->toBe(2)
            ->and(partyBalance($this->customer->fresh()))->toBe('0.00')
            ->and($this->customer->fresh()->opening_entry_id)->toBeNull()
            ->and(Worker::query()->count())->toBe(1)
            ->and(workerBalance($this->worker->fresh()))->toBe('0.00')
            ->and(TreasuryAccount::query()->count())->toBe(1)
            ->and(balanceOf($this->cash->fresh()))->toBe('0.00')
            ->and(Material::query()->count())->toBe($materials)
            ->and(app(MaterialStock::class)->levels([material('coal')->id])[material('coal')->id]['quantity']->toDecimal())->toBe('0.000')
            ->and(brickStock()['raw'])->toBe('0')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('starts document numbers again and lets new work be entered', function () {
        resetWith($this->owner, DataReset::CLEAR_ENTRIES);

        $entry = recordPathai($this->owner, '1000');

        expect($entry->number)->toEndWith('0001')
            ->and(brickStock()['raw'])->toBe('1,000');
    });

    it('logs the reset with its backup', function () {
        resetWith($this->owner, DataReset::CLEAR_ENTRIES);

        $log = Activity::query()->where('event', 'data_reset')->sole();
        expect($log->causer_id)->toBe($this->owner->id)
            ->and($log->properties['attributes']['mode'])->toBe(DataReset::CLEAR_ENTRIES);
    });

    it('leaves another kiln alone', function () {
        $other = makeSeededKiln();
        $otherOwner = makeUser($other, Role::Owner);
        actingInKiln($otherOwner, $other);
        makeTreasuryAccount($otherOwner, 'Other cash', '9000');
        actingInKiln($this->owner, $this->kiln);

        resetWith($this->owner, DataReset::CLEAR_ENTRIES);

        expect(DB::table('journal_entries')->where('kiln_id', $other->id)->count())->toBe(1);
    });
});

describe('factory reset', function () {
    it('deletes everything except the kiln profile and the owner, and creates the defaults again', function () {
        $manager = makeUser($this->kiln, Role::Manager);

        resetWith($this->owner, DataReset::FACTORY);

        expect(Party::query()->count())->toBe(0)
            ->and(Worker::query()->count())->toBe(0)
            ->and(TreasuryAccount::query()->count())->toBe(0)
            ->and(JournalEntry::query()->count())->toBe(0)
            ->and(Kiln::query()->find($this->kiln->id))->not->toBeNull()
            // Defaults are back.
            ->and(Material::query()->where('code', 'coal')->exists())->toBeTrue()
            ->and(labourCategory('pathai'))->not->toBeNull()
            ->and(product('awal'))->not->toBeNull()
            // The owner keeps access; the manager loses it.
            ->and($this->owner->fresh()->kilns()->whereKey($this->kiln->id)->exists())->toBeTrue()
            ->and($manager->fresh()->kilns()->whereKey($this->kiln->id)->exists())->toBeFalse()
            ->and($manager->fresh()->is_active)->toBeFalse()
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);

        // The kiln works again from scratch.
        makeTreasuryAccount($this->owner, 'New cash', '1000');
        expect(TreasuryAccount::query()->count())->toBe(1);
    });
});

describe('safety', function () {
    it('needs the owner', function () {
        $manager = makeUser($this->kiln, Role::Manager);
        $manager->givePermissionTo('data.manage');
        actingInKiln($manager, $this->kiln);

        expect(fn () => resetWith($manager, DataReset::CLEAR_ENTRIES))->toThrow(HttpException::class);
        expect(Sale::query()->count())->toBe(1);
    });

    it('needs the right password and the phrase', function () {
        expect(validationErrors(fn () => resetWith($this->owner, DataReset::CLEAR_ENTRIES, 'wrong')))->toHaveKey('password')
            ->and(validationErrors(fn () => resetWith($this->owner, DataReset::CLEAR_ENTRIES, 'password1', 'reset')))->toHaveKey('phrase')
            ->and(validationErrors(fn () => resetWith($this->owner, 'everything')))->toHaveKey('mode')
            ->and(Sale::query()->count())->toBe(1)
            ->and(BackupRun::query()->count())->toBe(0);
    });

    it('accepts the phrase in small letters', function () {
        resetWith($this->owner, DataReset::CLEAR_ENTRIES, 'password1', 'reset all data');

        expect(Sale::query()->count())->toBe(0);
    });

    it('deletes nothing when the backup fails', function () {
        $this->mock(BackupRunner::class)->shouldReceive('run')->andReturn(new BackupRun(['status' => BackupRun::FAILED, 'message' => 'Disk full']));

        expect(validationErrors(fn () => resetWith($this->owner, DataReset::CLEAR_ENTRIES)))->toHaveKey('mode')
            ->and(Sale::query()->count())->toBe(1);
    });
});

describe('the Data page', function () {
    it('is for users with the data permission, and only the owner sees reset', function () {
        $this->get(route('data.index'))->assertOk()->assertSee('Clear all entries')->assertSee('Factory reset');
        $this->get('/dashboard')->assertSee(route('data.index'), false);

        foreach ([Role::Manager, Role::Munshi, Role::DataEntry, Role::Viewer] as $role) {
            $this->actingAs(makeUser($this->kiln, $role))->get(route('data.index'))->assertForbidden();
        }
    });

    it('resets from the dialog and shows the backup', function () {
        Livewire::test(DataIndex::class)
            ->call('openReset', 'clear_entries')
            ->set('password', 'password1')
            ->set('phrase', 'RESET ALL DATA')
            ->call('runReset')
            ->assertHasNoErrors()
            ->assertSee('All entries cleared.')
            ->assertSee('.zip');

        expect(Sale::query()->count())->toBe(0);
    });

    it('shows a wrong password on its field', function () {
        Livewire::test(DataIndex::class)
            ->call('openReset', 'factory')
            ->set('password', 'nope')
            ->set('phrase', 'RESET ALL DATA')
            ->call('runReset')
            ->assertHasErrors(['password']);

        expect(Party::query()->count())->toBe(2);
    });
});
