<?php

namespace App\Actions\Trolleys;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\PartyRole;
use App\Models\Party;
use App\Models\Trolley;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit one of the kiln's trolleys. Changing its driver only affects later costs: costs
 * already recorded stay with the driver they were charged to.
 */
class SaveTrolley
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  number, driver_id, is_active
     */
    public function handle(User $actor, ?Trolley $trolley, array $input): Trolley
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);

        $data = Validator::make($input, [
            'number' => ['required', 'string', 'max:30', $this->uniqueInKiln('trolleys', 'number', $trolley?->id)],
            'driver_id' => ['required', 'integer'],
            'is_active' => ['boolean'],
        ], [], ['number' => __('trolley number'), 'driver_id' => __('driver')])->validate();

        $driver = Party::query()->withRole(PartyRole::Driver)->where('is_active', true)->find((int) $data['driver_id']);
        if ($driver === null) {
            throw ValidationException::withMessages(['driver_id' => __('Choose an active party with the driver role.')]);
        }

        $trolley ??= new Trolley;
        $trolley->fill([
            'number' => $data['number'],
            'driver_id' => $driver->id,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $trolley;
    }
}
