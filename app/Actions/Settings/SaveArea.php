<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Models\Area;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaveArea
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?Area $area, array $input): Area
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', $this->uniqueInKiln('areas', 'name', $area?->id)],
            'name_ur' => ['nullable', 'string', 'max:100', $this->uniqueInKiln('areas', 'name_ur', $area?->id)],
        ])->validate();

        return DB::transaction(function () use ($area, $data): Area {
            $area ??= new Area(['is_active' => true]);
            $area->fill($data)->save();

            return $area;
        });
    }
}
