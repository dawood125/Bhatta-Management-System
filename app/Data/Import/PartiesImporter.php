<?php

namespace App\Data\Import;

use App\Actions\Parties\SaveParty;
use App\Actions\Settings\SaveArea;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\Area;
use App\Models\Party;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Customers, suppliers, drivers and sales agents, with their opening balances. */
class PartiesImporter extends Importer
{
    public function key(): string
    {
        return 'parties';
    }

    public function title(): string
    {
        return __('Parties');
    }

    public function description(): string
    {
        return __('Customers, suppliers, drivers and sales agents, with what they owe or are owed.');
    }

    public function columns(): array
    {
        return [
            'Name' => [true, __('As you call them.')],
            'Name (Urdu)' => [false, __('Optional.')],
            'Type' => [true, __('Customer, Supplier, Driver or Sales agent. Several: separate with commas.')],
            'Phone' => [false, ''],
            'Phone 2' => [false, ''],
            'Address' => [false, ''],
            'Area' => [false, __('Created if it does not exist yet.')],
            'Opening balance' => [false, __('0 or blank if nothing is owed.')],
            'Who owes' => [false, __('"They owe us" or "We owe them". Blank: customers owe us, others are owed.')],
            'As of date' => [false, __('Blank: today.')],
        ];
    }

    public function examples(): array
    {
        return [
            ['Ali Traders', 'علی ٹریڈرز', 'Customer', '03001234567', '', 'Main bazar', 'City', '25000', 'They owe us', now()->format('d-m-Y')],
            ['Rafiq Coal', '', 'Supplier', '03111234567', '', '', '', '40000', 'We owe them', now()->format('d-m-Y')],
            ['Bashir Driver', '', 'Driver', '', '', '', '', '0', '', ''],
        ];
    }

    public function allowedValues(): array
    {
        return [
            'Type' => ['Customer', 'Supplier', 'Driver', 'Sales agent'],
            'Who owes' => ['They owe us', 'We owe them'],
        ];
    }

    public function allowed(User $actor): bool
    {
        return $actor->can(Permission::PartiesManage->value);
    }

    /** @param  array<string, mixed>  $values */
    public function describe(array $values): string
    {
        return $this->value($values, 'Name');
    }

    public function importRow(User $actor, array $values, string $key): void
    {
        $name = $this->required($values, 'Name');
        if (Party::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['Name' => __('A party named ":name" already exists.', ['name' => $name])]);
        }

        $roles = $this->roles($this->required($values, 'Type'));
        $direction = match (self::plain($this->value($values, 'Who owes'))) {
            '' => in_array(PartyRole::Customer, $roles, true) ? SaveParty::THEY_OWE_US : SaveParty::WE_OWE_THEM,
            'theyoweus', 'receivable', 'theyowe' => SaveParty::THEY_OWE_US,
            'weowethem', 'payable', 'weowe' => SaveParty::WE_OWE_THEM,
            default => throw ValidationException::withMessages(['Who owes' => __('Who owes must be "They owe us" or "We owe them".')]),
        };

        app(SaveParty::class)->handle($actor, null, [
            'roles' => array_map(fn (PartyRole $r) => $r->value, $roles),
            'name' => $name,
            'name_ur' => $this->value($values, 'Name (Urdu)'),
            'phone' => $this->phone($values, 'Phone'),
            'phone_2' => $this->phone($values, 'Phone 2'),
            'address' => $this->value($values, 'Address'),
            'area_id' => $this->area($actor, $this->value($values, 'Area')),
            'sales_agent_id' => '',
            'notes' => '',
            'opening_amount' => $this->number($values, 'Opening balance') ?: '0',
            'opening_direction' => $direction,
            'opening_date' => $this->date($values, 'As of date'),
        ], $key);
    }

    /** @return list<PartyRole> */
    private function roles(string $text): array
    {
        $roles = [];
        foreach (preg_split('/[,،\/;]+/u', $text) ?: [] as $word) {
            $plain = self::plain($word);
            if ($plain === '') {
                continue;
            }

            $role = match (true) {
                in_array($plain, ['customer', 'customers', 'گاہک'], true) => PartyRole::Customer,
                in_array($plain, ['supplier', 'suppliers', 'سپلائر'], true) => PartyRole::Supplier,
                in_array($plain, ['driver', 'drivers', 'ڈرائیور'], true) => PartyRole::Driver,
                in_array($plain, ['salesagent', 'agent', 'agents', 'ایجنٹ'], true) => PartyRole::Agent,
                default => throw ValidationException::withMessages(['Type' => __('Type ":type" is not known. Use Customer, Supplier, Driver or Sales agent.', ['type' => trim($word)])]),
            };
            $roles[$role->value] = $role;
        }

        return array_values($roles);
    }

    private function area(User $actor, string $name): string
    {
        if ($name === '') {
            return '';
        }

        $area = Area::query()->where('name', $name)->orWhere('name_ur', $name)->first()
            ?? app(SaveArea::class)->handle($actor, null, ['name' => $name, 'name_ur' => '']);

        return (string) $area->id;
    }
}
