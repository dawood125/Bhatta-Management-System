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
     * @param  array<string, mixed>  $input  name, name_ur, unit_id, kind, sort_order
     */
    public function handle(User $actor, ?Material $material, array $input): Material
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            // 90, not 100: the usage account adds " used" to the name and has room for 100.
            'name' => ['required', 'string', 'max:90', $this->uniqueInKiln('materials', 'name', $material?->id)],
            'name_ur' => ['nullable', 'string', 'max:90', $this->uniqueInKiln('materials', 'name_ur', $material?->id)],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'kind' => ['required', Rule::enum(MaterialKind::class)],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
        ])->validate();

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
