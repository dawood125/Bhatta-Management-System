<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveUnit
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?Unit $unit, array $input): Unit
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input, ['name_ur', 'symbol']);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', $this->uniqueInKiln('units', 'name', $unit?->id)],
            'name_ur' => ['nullable', 'string', 'max:100', $this->uniqueInKiln('units', 'name_ur', $unit?->id)],
            'symbol' => ['nullable', 'string', 'max:20'],
            'decimals' => ['required', 'integer', 'between:0,3'],
        ])->validate();

        // Built-in units drive calculations (e.g. bricks are always whole numbers).
        if ($unit !== null && $unit->is_system && (int) $data['decimals'] !== $unit->decimals) {
            throw ValidationException::withMessages(['decimals' => __('The decimal places of a built-in unit cannot be changed.')]);
        }

        return DB::transaction(function () use ($unit, $data): Unit {
            $unit ??= new Unit(['is_active' => true, 'is_system' => false, 'sort_order' => ((int) Unit::query()->max('sort_order')) + 10]);
            $unit->fill($data)->save();

            return $unit;
        });
    }
}
