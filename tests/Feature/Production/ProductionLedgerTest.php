<?php

use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerVerifier;
use App\Actions\Materials\ReverseMaterialEntry;
use App\Actions\Production\RecordBrickAdjustment;
use App\Actions\Production\RecordNakaasi;
use App\Actions\Production\ReverseProductionEntry;
use App\Enums\PartyRole;
use App\Enums\ProductionEntryKind;
use App\Enums\Role;
use App\Enums\StageKind;
use App\Models\BrickMovement;
use App\Models\MaterialUsage;
use App\Models\ProductionEntry;
use App\Production\BrickStock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

/** The field names of a validation failure. */
function productionErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    test()->fail('Expected a validation error.');
}

describe('acceptance example (plan M6)', function () {
    it('follows 12,000 bricks from Pathai to saleable stock by grade', function () {
        recordPathai($this->owner, '12,000', '500');
        expect(brickStock())->toBe(['raw' => '11,500', 'in_firing' => '0', 'awaiting' => '0', 'saleable' => []]);

        recordLoading($this->owner, '11,000');
        expect(brickStock())->toBe(['raw' => '500', 'in_firing' => '11,000', 'awaiting' => '0', 'saleable' => []]);

        recordFiring($this->owner, '11,000');
        expect(brickStock())->toBe(['raw' => '500', 'in_firing' => '0', 'awaiting' => '11,000', 'saleable' => []]);

        recordNakaasi($this->owner, ['awal' => '8,000', 'doom' => '2,000', 'khingar' => '700', 'tooty' => '300']);
        expect(brickStock())->toBe([
            'raw' => '500', 'in_firing' => '0', 'awaiting' => '0',
            'saleable' => ['awal' => '8,000', 'doom' => '2,000', 'khingar' => '700', 'tooty' => '300'],
        ]);

        expect(productionErrors(fn () => recordLoading($this->owner, '501')))->toBe(['quantity'])
            ->and(brickStock()['raw'])->toBe('500')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('entries', function () {
    it('numbers each kind of entry on its own', function () {
        $year = now()->year;
        $numbers = [
            recordPathai($this->owner, '1000')->number,
            recordLoading($this->owner, '500')->number,
            recordFiring($this->owner, '500')->number,
            recordNakaasi($this->owner, ['awal' => '500'])->number,
            recordBrickAdjustment($this->owner, StageKind::Raw, RecordBrickAdjustment::ADD, '10')->number,
            recordPathai($this->owner, '1000')->number,
        ];

        expect($numbers)->toBe(["PT-{$year}-0001", "LD-{$year}-0001", "FR-{$year}-0001", "NK-{$year}-0001", "BA-{$year}-0001", "PT-{$year}-0002"]);
    });

    it('keeps rejected Pathai bricks out of stock', function () {
        $entry = recordPathai($this->owner, '1200', '1200', ['team' => 'Rafiq family']);

        expect($entry->accepted()->isZero())->toBeTrue()
            ->and(brickStock()['raw'])->toBe('0')
            ->and($entry->team)->toBe('Rafiq family')
            ->and(BrickMovement::query()->count())->toBe(0);
    });

    it('checks Pathai figures', function (string $made, string $rejected, string $field) {
        expect(productionErrors(fn () => recordPathai($this->owner, $made, $rejected)))->toContain($field);
    })->with([
        'nothing made' => ['0', '0', 'made'],
        'half a brick' => ['100.5', '0', 'made'],
        'text' => ['many', '0', 'made'],
        'more rejected than made' => ['100', '101', 'rejected'],
        'negative rejected' => ['100', '-1', 'rejected'],
    ]);

    it('refuses future dates', function () {
        expect(productionErrors(fn () => recordPathai($this->owner, '100', '0', ['entry_date' => now()->addDay()->format('Y-m-d')])))->toBe(['entry_date']);
    });

    it('never moves bricks that were not there yet on that day', function () {
        recordPathai($this->owner, '1000');

        expect(productionErrors(fn () => recordLoading($this->owner, '100', ['entry_date' => now()->subDay()->format('Y-m-d')])))->toBe(['quantity']);
    });

    it('refuses a back-dated move that would make a later day negative', function () {
        recordPathai($this->owner, '1000', '0', ['entry_date' => now()->subDays(5)->format('Y-m-d')]);
        recordLoading($this->owner, '800', ['entry_date' => now()->subDay()->format('Y-m-d')]);

        expect(productionErrors(fn () => recordLoading($this->owner, '300', ['entry_date' => now()->subDays(3)->format('Y-m-d')])))->toBe(['quantity']);
        recordLoading($this->owner, '200', ['entry_date' => now()->subDays(3)->format('Y-m-d')]);
        expect(brickStock()['raw'])->toBe('0');
    });

    it('returns the first entry when the same form is sent twice', function () {
        $key = (string) Str::uuid();
        $first = recordPathai($this->owner, '1000', key: $key);
        $second = recordPathai($this->owner, '1000', key: $key);

        expect($second->id)->toBe($first->id)->and(brickStock()['raw'])->toBe('1,000');
        expect(fn () => recordFiring($this->owner, '1', key: $key))->toThrow(LedgerIntegrityException::class);
    });

    it('never edits or deletes entries and movements', function () {
        $entry = recordPathai($this->owner, '1000');

        expect(fn () => $entry->update(['notes' => 'changed']))->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $entry->delete())->toThrow(LedgerIntegrityException::class)
            ->and(fn () => BrickMovement::query()->first()->delete())->toThrow(LedgerIntegrityException::class);
    });
});

describe('firing and fuel', function () {
    beforeEach(function () {
        $supplier = makeParty($this->owner, 'Coal Supplier', [PartyRole::Supplier]);
        recordPurchase($this->owner, $supplier, [[material('coal'), '10', '40000'], [material('bora'), '100', '300']]);
        recordPathai($this->owner, '5000');
        recordLoading($this->owner, '5000');
    });

    it('records bricks fired and the fuel burnt, taking the fuel out of material stock', function () {
        $entry = recordFiring($this->owner, '4000', [[material('coal'), '1.5'], [material('bora'), '10']], ['notes' => 'Line 4']);

        expect(brickStock()['awaiting'])->toBe('4,000')
            ->and(brickStock()['in_firing'])->toBe('1,000')
            ->and(stockOf(material('coal')))->toBe(['8.500', '340000.00'])
            ->and(stockOf(material('bora'))[0])->toBe('90.000')
            ->and($entry->materialUsage->total_cost->toDecimal())->toBe('63000.00')
            ->and($entry->materialUsage->notes)->toBe('Line 4')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('records fuel alone, and needs bricks or fuel', function () {
        $entry = recordFiring($this->owner, '', [[material('coal'), '1']]);

        expect($entry->quantity->isZero())->toBeTrue()
            ->and(brickStock()['in_firing'])->toBe('5,000')
            ->and(stockOf(material('coal'))[0])->toBe('9.000')
            ->and(productionErrors(fn () => recordFiring($this->owner, '')))->toBe(['quantity']);
    });

    it('refuses more fuel than is in stock, on the fuel line, and records nothing', function () {
        expect(productionErrors(fn () => recordFiring($this->owner, '1000', [[material('coal'), '10.001']])))->toBe(['fuel.0.quantity'])
            ->and(ProductionEntry::query()->where('kind', ProductionEntryKind::Firing)->count())->toBe(0)
            ->and(MaterialUsage::query()->count())->toBe(0)
            ->and(brickStock()['in_firing'])->toBe('5,000');
    });

    it('lets the data entry role record firing with fuel', function () {
        $entry = recordFiring(makeUser($this->kiln, Role::DataEntry), '100', [[material('coal'), '0.5']]);

        expect($entry->material_usage_id)->not->toBeNull();
    });

    it('reverses firing together with its fuel, and refuses to reverse that fuel on its own', function () {
        $entry = recordFiring($this->owner, '4000', [[material('coal'), '2']]);

        expect(productionErrors(fn () => app(ReverseMaterialEntry::class)->handle($this->owner, $entry->materialUsage->entry, ['reason' => 'Wrong amount'], (string) Str::uuid())))->toBe(['reason']);

        app(ReverseProductionEntry::class)->handle($this->owner, $entry, ['reason' => 'Wrong day entered']);

        expect(brickStock()['in_firing'])->toBe('5,000')
            ->and(brickStock()['awaiting'])->toBe('0')
            ->and(stockOf(material('coal')))->toBe(['10.000', '400000.00'])
            ->and($entry->fresh()->materialUsage->isReversed())->toBeTrue()
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('Nakaasi', function () {
    beforeEach(function () {
        recordPathai($this->owner, '3000');
        recordLoading($this->owner, '3000');
        recordFiring($this->owner, '3000');
    });

    it('refuses to unload more than is awaiting Nakaasi', function () {
        expect(productionErrors(fn () => recordNakaasi($this->owner, ['awal' => '2500', 'doom' => '501'])))->toBe(['lines']);
    });

    it('adds by-products in other units without counting them as bricks', function () {
        $entry = recordNakaasi($this->owner, ['awal' => '2000', 'tooty' => '100', 'rora' => '2.5']);

        expect($entry->quantity->format(0))->toBe('2,100')
            ->and(brickStock()['awaiting'])->toBe('900')
            ->and(brickStock()['saleable'])->toBe(['awal' => '2,000', 'rora' => '2.5', 'tooty' => '100']);
    });

    it('skips grades left empty and points errors at the right line', function () {
        $lines = [
            ['product_id' => (string) product('awal')->id, 'quantity' => '1000'],
            ['product_id' => (string) product('doom')->id, 'quantity' => ''],
            ['product_id' => (string) product('khingar')->id, 'quantity' => '12.5'],
        ];

        expect(productionErrors(fn () => app(RecordNakaasi::class)->handle($this->owner, ['entry_date' => now()->format('Y-m-d'), 'notes' => '', 'lines' => $lines], (string) Str::uuid())))
            ->toBe(['lines.2.quantity']);

        $lines[2]['quantity'] = '';
        app(RecordNakaasi::class)->handle($this->owner, ['entry_date' => now()->format('Y-m-d'), 'notes' => '', 'lines' => $lines], (string) Str::uuid());
        expect(brickStock()['saleable'])->toBe(['awal' => '1,000']);
    });

    it('needs some bricks, and only grades and by-products', function () {
        expect(productionErrors(fn () => recordNakaasi($this->owner, ['awal' => ''])))->toBe(['lines'])
            ->and(productionErrors(fn () => recordNakaasi($this->owner, ['rora' => '3'])))->toBe(['lines'])
            ->and(productionErrors(fn () => recordNakaasi($this->owner, ['kachi' => '10'])))->toBe(['lines.0.product_id']);
    });
});

describe('adjustments', function () {
    it('corrects any stage with a reason, never below zero', function () {
        recordPathai($this->owner, '1000');
        recordBrickAdjustment($this->owner, StageKind::Raw, RecordBrickAdjustment::REMOVE, '50', reason: 'Broken in rain');
        recordBrickAdjustment($this->owner, StageKind::Saleable, RecordBrickAdjustment::ADD, '200', 'doom', 'Counted more');

        expect(brickStock()['raw'])->toBe('950')
            ->and(brickStock()['saleable'])->toBe(['doom' => '200'])
            ->and(productionErrors(fn () => recordBrickAdjustment($this->owner, StageKind::Saleable, RecordBrickAdjustment::REMOVE, '201', 'doom')))->toBe(['quantity'])
            ->and(productionErrors(fn () => recordBrickAdjustment($this->owner, StageKind::Saleable, RecordBrickAdjustment::ADD, '1')))->toBe(['product_id'])
            ->and(productionErrors(fn () => recordBrickAdjustment($this->owner, StageKind::Raw, RecordBrickAdjustment::ADD, '1', reason: '')))->toBe(['reason']);
    });

    it('is only for the owner and manager', function (Role $role) {
        recordBrickAdjustment(makeUser($this->kiln, $role), StageKind::Raw, RecordBrickAdjustment::ADD, '10');
    })->throws(AuthorizationException::class)->with([Role::Munshi, Role::DataEntry, Role::Viewer]);
});

describe('reversals', function () {
    it('reverses Nakaasi, returning the bricks to awaiting Nakaasi', function () {
        recordPathai($this->owner, '1000');
        recordLoading($this->owner, '1000');
        recordFiring($this->owner, '1000');
        $nakaasi = recordNakaasi($this->owner, ['awal' => '900', 'tooty' => '100', 'rora' => '1']);

        app(ReverseProductionEntry::class)->handle($this->owner, $nakaasi, ['reason' => 'Grades mixed up']);

        expect(brickStock())->toBe(['raw' => '0', 'in_firing' => '0', 'awaiting' => '1,000', 'saleable' => []])
            ->and($nakaasi->fresh()->isReversed())->toBeTrue()
            ->and($nakaasi->fresh()->reversal_reason)->toBe('Grades mixed up')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses to reverse bricks that have moved on since, and a second reversal', function () {
        $pathai = recordPathai($this->owner, '1000');
        $loading = recordLoading($this->owner, '600');

        expect(productionErrors(fn () => app(ReverseProductionEntry::class)->handle($this->owner, $pathai, ['reason' => 'Wrong figure'])))->toBe(['reason']);

        app(ReverseProductionEntry::class)->handle($this->owner, $loading, ['reason' => 'Not loaded yet']);
        app(ReverseProductionEntry::class)->handle($this->owner, $pathai, ['reason' => 'Wrong figure']);

        expect(brickStock()['raw'])->toBe('0')
            ->and(productionErrors(fn () => app(ReverseProductionEntry::class)->handle($this->owner, $pathai->fresh(), ['reason' => 'Again please'])))->toBe(['reason']);
    });

    it('is only for the owner and manager, with a reason', function () {
        $pathai = recordPathai($this->owner, '1000');

        expect(fn () => app(ReverseProductionEntry::class)->handle(makeUser($this->kiln, Role::Munshi), $pathai, ['reason' => 'Wrong figure']))->toThrow(AuthorizationException::class)
            ->and(productionErrors(fn () => app(ReverseProductionEntry::class)->handle($this->owner, $pathai, ['reason' => ''])))->toBe(['reason']);
    });
});

describe('permissions and integrity', function () {
    it('lets the data entry role record production but not the viewer', function () {
        recordPathai(makeUser($this->kiln, Role::DataEntry), '100');

        expect(fn () => recordPathai(makeUser($this->kiln, Role::Viewer), '100'))->toThrow(AuthorizationException::class);
    });

    it('lets the verifier catch impossible stock and incomplete reversals', function () {
        $pathai = recordPathai($this->owner, '1000');
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        DB::table('brick_movements')->insert([
            'kiln_id' => $this->kiln->id, 'production_entry_id' => $pathai->id, 'movement_date' => now()->format('Y-m-d'),
            'stage_from' => 'in_firing', 'stage_to' => null, 'product_id' => null, 'quantity' => 5000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('stage in_firing holds -5000');

        DB::table('brick_movements')->where('stage_from', 'in_firing')->delete();
        DB::table('production_entries')->where('id', $pathai->id)->update(['reversed_at' => now(), 'reversal_reason' => 'x']);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain("Production entry {$pathai->number}");
    });
});

describe('daily view', function () {
    it('shows each stage at the start and end of the day, Pathai totals and duplicates', function () {
        recordPathai($this->owner, '2000', '0', ['entry_date' => now()->subDay()->format('Y-m-d')]);
        recordPathai($this->owner, '1000', '100', ['team' => 'Team A']);
        recordPathai($this->owner, '1000', '100', ['team' => 'team a ']); // same again: possible duplicate
        recordLoading($this->owner, '1500');

        $day = app(BrickStock::class)->day(now()->startOfDay());
        $raw = collect($day['slots'])->first(fn ($slot) => $slot['stage'] === StageKind::Raw);

        expect($raw['opening']->format(0))->toBe('2,000')
            ->and($raw['in']->format(0))->toBe('1,800')
            ->and($raw['out']->format(0))->toBe('1,500')
            ->and($raw['closing']->format(0))->toBe('2,300')
            ->and($day['pathai']['made']->format(0))->toBe('2,000')
            ->and($day['pathai']['rejected']->format(0))->toBe('200')
            ->and($day['pathai']['accepted']->format(0))->toBe('1,800')
            ->and(count($day['duplicates']))->toBe(2)
            ->and($day['entries'])->toHaveCount(3);
    });
});
