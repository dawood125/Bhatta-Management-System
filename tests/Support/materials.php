<?php

use App\Actions\Materials\RecordMaterialAdjustment;
use App\Actions\Materials\RecordMaterialPurchase;
use App\Actions\Materials\RecordMaterialUsage;
use App\Materials\MaterialStock;
use App\Models\Material;
use App\Models\MaterialAdjustment;
use App\Models\MaterialPurchase;
use App\Models\MaterialPurchaseCharge;
use App\Models\MaterialUsage;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\User;
use Illuminate\Support\Str;

/** A default material of the current kiln by its code (soil, kassu, coal, bora, tuka, waste). */
function material(string $code): Material
{
    return Material::query()->with('unit')->where('code', $code)->sole();
}

/**
 * Record a purchase through the real action. On credit from $supplier, or paid now when
 * $extra has payment=paid and account_id.
 *
 * @param  list<array{0: Material, 1: string, 2: string}>  $lines  [material, quantity, rate]
 * @param  list<array{0: string, 1: string, 2?: string, 3?: Party|TreasuryAccount|null, 4?: string}>  $charges  [kind, amount, payee, party or account, details]
 */
function recordPurchase(User $actor, ?Party $supplier, array $lines, array $charges = [], array $extra = [], ?string $key = null): MaterialPurchase
{
    return app(RecordMaterialPurchase::class)->handle($actor, array_merge([
        'purchase_date' => now()->format('Y-m-d'),
        'payment' => MaterialPurchase::ON_CREDIT,
        'supplier_id' => $supplier !== null ? (string) $supplier->id : '',
        'account_id' => '',
        'reference' => '',
        'notes' => '',
        'lines' => array_map(fn (array $line) => ['material_id' => (string) $line[0]->id, 'quantity' => $line[1], 'rate' => $line[2]], $lines),
        'charges' => array_map(fn (array $charge) => [
            'kind' => $charge[0],
            'description' => $charge[4] ?? '',
            'amount' => $charge[1],
            'payee' => $charge[2] ?? MaterialPurchaseCharge::WITH_GOODS,
            'party_id' => ($charge[3] ?? null) instanceof Party ? (string) $charge[3]->id : '',
            'account_id' => ($charge[3] ?? null) instanceof TreasuryAccount ? (string) $charge[3]->id : '',
        ], $charges),
    ], $extra), $key ?? (string) Str::uuid());
}

/**
 * @param  list<array{0: Material, 1: string, 2?: string}>  $lines  [material, quantity, remarks]
 */
function recordUsage(User $actor, array $lines, array $extra = [], ?string $key = null): MaterialUsage
{
    return app(RecordMaterialUsage::class)->handle($actor, array_merge([
        'usage_date' => now()->format('Y-m-d'),
        'notes' => '',
        'lines' => array_map(fn (array $line) => ['material_id' => (string) $line[0]->id, 'quantity' => $line[1], 'memo' => $line[2] ?? ''], $lines),
    ], $extra), $key ?? (string) Str::uuid());
}

function recordOpeningStock(User $actor, Material $material, string $quantity, string $value, array $extra = []): MaterialAdjustment
{
    return app(RecordMaterialAdjustment::class)->handle($actor, array_merge([
        'kind' => MaterialAdjustment::OPENING,
        'material_id' => (string) $material->id,
        'adjustment_date' => now()->subDays(30)->format('Y-m-d'),
        'quantity' => $quantity,
        'value' => $value,
        'reason' => '',
    ], $extra), (string) Str::uuid());
}

function recordShortage(User $actor, Material $material, string $quantity, string $reason = 'Counted less', array $extra = []): MaterialAdjustment
{
    return app(RecordMaterialAdjustment::class)->handle($actor, array_merge([
        'kind' => MaterialAdjustment::SHORTAGE,
        'material_id' => (string) $material->id,
        'adjustment_date' => now()->format('Y-m-d'),
        'quantity' => $quantity,
        'value' => '',
        'reason' => $reason,
    ], $extra), (string) Str::uuid());
}

/**
 * Stock of a material as plain strings, e.g. ['600.000', '33000.00'].
 *
 * @return array{0: string, 1: string}
 */
function stockOf(Material $material): array
{
    $level = app(MaterialStock::class)->levels([$material->id])[$material->id];

    return [$level['quantity']->toDecimal(), $level['value']->toDecimal()];
}
