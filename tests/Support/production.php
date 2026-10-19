<?php

use App\Actions\Production\RecordBrickAdjustment;
use App\Actions\Production\RecordFiring;
use App\Actions\Production\RecordLoading;
use App\Actions\Production\RecordNakaasi;
use App\Actions\Production\RecordPathai;
use App\Enums\StageKind;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Production\BrickStock;
use Illuminate\Support\Str;

/** A default product of the current kiln by its code (awal, doom, khingar, tile, tooty, rora, kachi). */
function product(string $code): Product
{
    return Product::query()->with('unit')->where('code', $code)->sole();
}

function recordPathai(User $actor, string $made, string $rejected = '0', array $extra = [], ?string $key = null): ProductionEntry
{
    return app(RecordPathai::class)->handle($actor, array_merge([
        'entry_date' => now()->format('Y-m-d'), 'team' => '', 'made' => $made, 'rejected' => $rejected, 'notes' => '',
    ], $extra), $key ?? (string) Str::uuid());
}

function recordLoading(User $actor, string $quantity, array $extra = []): ProductionEntry
{
    return app(RecordLoading::class)->handle($actor, array_merge([
        'entry_date' => now()->format('Y-m-d'), 'quantity' => $quantity, 'notes' => '',
    ], $extra), (string) Str::uuid());
}

/**
 * @param  list<array{0: Material, 1: string}>  $fuel  [material, quantity]
 */
function recordFiring(User $actor, string $quantity, array $fuel = [], array $extra = [], ?string $key = null): ProductionEntry
{
    return app(RecordFiring::class)->handle($actor, array_merge([
        'entry_date' => now()->format('Y-m-d'), 'quantity' => $quantity, 'notes' => '',
        'fuel' => array_map(fn (array $line) => ['material_id' => (string) $line[0]->id, 'quantity' => $line[1]], $fuel),
    ], $extra), $key ?? (string) Str::uuid());
}

/**
 * @param  array<string, string>  $grades  product code => quantity
 */
function recordNakaasi(User $actor, array $grades, array $extra = []): ProductionEntry
{
    $lines = [];
    foreach ($grades as $code => $quantity) {
        $lines[] = ['product_id' => (string) product($code)->id, 'quantity' => $quantity];
    }

    return app(RecordNakaasi::class)->handle($actor, array_merge([
        'entry_date' => now()->format('Y-m-d'), 'notes' => '', 'lines' => $lines,
    ], $extra), (string) Str::uuid());
}

function recordBrickAdjustment(User $actor, StageKind $stage, string $direction, string $quantity, ?string $productCode = null, string $reason = 'Counted again', array $extra = []): ProductionEntry
{
    return app(RecordBrickAdjustment::class)->handle($actor, array_merge([
        'entry_date' => now()->format('Y-m-d'),
        'stage' => $stage->value,
        'product_id' => $productCode !== null ? (string) product($productCode)->id : '',
        'direction' => $direction,
        'quantity' => $quantity,
        'reason' => $reason,
    ], $extra), (string) Str::uuid());
}

/**
 * Brick stock as plain numbers: the three ungraded stages and saleable stock by product code.
 *
 * @return array{raw: string, in_firing: string, awaiting: string, saleable: array<string, string>}
 */
function brickStock(): array
{
    $levels = app(BrickStock::class)->levels();
    $codes = Product::query()->pluck('code', 'id');
    $saleable = [];
    foreach ($levels['saleable'] as $id => $quantity) {
        if (! $quantity->isZero()) {
            $saleable[$codes[$id] ?? (string) $id] = $quantity->format(0);
        }
    }
    ksort($saleable);

    return [
        'raw' => $levels['raw']->format(0),
        'in_firing' => $levels['in_firing']->format(0),
        'awaiting' => $levels['fired_awaiting_unloading']->format(0),
        'saleable' => $saleable,
    ];
}
