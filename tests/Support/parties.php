<?php

use App\Actions\Parties\SaveParty;
use App\Enums\PartyRole;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Create a party through the real action.
 *
 * @param  list<PartyRole>  $roles
 */
function makeParty(User $actor, string $name, array $roles = [PartyRole::Customer], string $opening = '0', string $direction = SaveParty::THEY_OWE_US, array $extra = []): Party
{
    return app(SaveParty::class)->handle($actor, null, array_merge([
        'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
        'name' => $name,
        'name_ur' => '',
        'phone' => '',
        'phone_2' => '',
        'address' => '',
        'area_id' => '',
        'sales_agent_id' => '',
        'notes' => '',
        'opening_amount' => $opening,
        'opening_direction' => $direction,
        'opening_date' => now()->subDays(30)->format('Y-m-d'),
    ], $extra), (string) Str::uuid());
}

/** Re-save a party with changed fields (everything else as it is now). */
function updateParty(User $actor, Party $party, array $changes): Party
{
    $party = $party->fresh();

    return app(SaveParty::class)->handle($actor, $party, array_merge([
        'roles' => array_map(fn (PartyRole $role) => $role->value, $party->roles()),
        'name' => $party->name,
        'name_ur' => $party->name_ur,
        'phone' => $party->phone,
        'phone_2' => $party->phone_2,
        'address' => $party->address,
        'area_id' => $party->area_id,
        'sales_agent_id' => $party->sales_agent_id,
        'notes' => $party->notes,
        'opening_amount' => $party->opening_balance->abs()->toDecimal(),
        'opening_direction' => $party->opening_balance->isNegative() ? SaveParty::WE_OWE_THEM : SaveParty::THEY_OWE_US,
        'opening_date' => $party->opening_date->format('Y-m-d'),
    ], $changes), (string) Str::uuid());
}
