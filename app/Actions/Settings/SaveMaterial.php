<?php

namespace App\Actions\Settings;

use App\Accounting\ChartOfAccounts;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\MaterialKind;
use App\Models\LedgerAccount;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit a material (soil, coal, ...). Its unit is fixed once stock has been recorded,
 * because every quantity in its history is counted in that unit.
 */
class SaveMaterial
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  name, name_ur, unit_id, kind, sort_order, bricks_per_unit, bricks_per_big_unit
     */
    public function handle(User $actor, ?Material $material, array $input): Material
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input, ['bricks_per_unit', 'bricks_per_big_unit']);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            // 90, not 100: the usage account adds " used" to the name and has room for 100.
            'name' => ['required', 'string', 'max:90', $this->uniqueInKiln('materials', 'name', $material?->id)],
            'name_ur' => ['nullable', 'string', 'max:90', $this->uniqueInKiln('materials', 'name_ur', $material?->id)],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'kind' => ['required', Rule::enum(MaterialKind::class)],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
            'bricks_per_unit' => ['nullable', 'integer', 'between:1,10000000'],
            'bricks_per_big_unit' => ['nullable', 'integer', 'between:1,10000000'],
        ], [], [
            'bricks_per_unit' => __('bricks from one unit'),
            'bricks_per_big_unit' => __('bricks from one big trolley'),
        ])->validate();

        // Only raw materials go into the bricks at Pathai.
        $isRaw = $data['kind'] === MaterialKind::RawMaterial->value;
        $data['bricks_per_unit'] = $isRaw && filled($data['bricks_per_unit'] ?? null) ? (int) $data['bricks_per_unit'] : null;
        $data['bricks_per_big_unit'] = $isRaw && filled($data['bricks_per_big_unit'] ?? null) ? (int) $data['bricks_per_big_unit'] : null;

        if ($data['bricks_per_big_unit'] !== null && $data['bricks_per_unit'] === null) {
            throw ValidationException::withMessages(['bricks_per_unit' => __('Enter the bricks from one normal trolley first.')]);
        }

        if ($material !== null && (int) $data['unit_id'] !== $material->unit_id
            && MaterialMovement::query()->where('material_id', $material->id)->exists()) {
            throw ValidationException::withMessages(['unit_id' => __('This material already has stock entries, so its unit can no longer change.')]);
        }

        return DB::transaction(function () use ($material, $data): Material {
            $material ??= new Material(['is_active' => true]);
            $material->fill($data)->save();

            // Its usage account (once it has one) carries the same name in reports.
            if ($material->usage_account_id !== null) {
                [$name, $nameUr] = ChartOfAccounts::usageAccountNames($material);
                LedgerAccount::query()->whereKey($material->usage_account_id)->firstOrFail()->fill(['name' => $name, 'name_ur' => $nameUr])->save();
            }

            return $material;
        });
    }
}
