<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Models\ProductionStage;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Stages can only be renamed; their kind and order are fixed by the system.
 */
class RenameStage
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ProductionStage $stage, array $input): ProductionStage
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'name_ur' => ['nullable', 'string', 'max:100'],
        ])->validate();

        $stage->fill(['name' => $data['name'], 'name_ur' => $data['name_ur']])->save();

        return $stage;
    }
}
