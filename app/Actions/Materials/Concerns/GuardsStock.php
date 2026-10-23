<?php

namespace App\Actions\Materials\Concerns;

use App\Materials\MaterialStock;
use App\Models\Material;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Material stock can never go below zero, on the entry's date or any later day. Materials are
 * locked (in id order, to avoid deadlocks) so two people can't use the same stock at once.
 */
trait GuardsStock
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Material>
     */
    protected function lockMaterials(array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return Material::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->with('unit')->get()->keyBy('id');
    }

    protected function assertStockAllows(Material $material, CarbonInterface $date, Quantity $change, string $field): void
    {
        $lowest = app(MaterialStock::class)->lowestFrom($material, $date, $change);

        if ($lowest->isNegative()) {
            throw ValidationException::withMessages([
                $field => __('Not enough :material in stock. This would leave :quantity :unit.', [
                    'material' => $material->displayName(),
                    'quantity' => $lowest->format($material->unit->decimals),
                    'unit' => $material->unit->symbol ?? $material->unit->displayName(),
                ]),
            ]);
        }
    }
}
