<?php

namespace App\Livewire\Parties;

use App\Actions\Parties\SaveParty;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Party;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "New customer" on the sale page and "New supplier" on the purchase page: a short form that adds
 * the party without leaving the page, puts it in the page's picker and chooses it.
 *
 * The page opens it with  $dispatchTo('parties.quick-add-party', 'open-quick-party')  and listens
 * for "quick-party-added" (role, id). The picker learns the new option from "combobox-add".
 *
 * @property-read Collection<int, Party> $similarParties Livewire computed property
 */
class QuickAddParty extends Component
{
    use RunsActions;

    /** customer or supplier */
    #[Locked]
    public string $role = 'customer';

    /** Id of the JSON options script of the page's picker. */
    #[Locked]
    public string $source = '';

    public bool $show = false;

    /** @var array<string, string> */
    public array $form = [];

    public string $formKey = '';

    public function mount(string $role, string $source): void
    {
        $this->authorize(Permission::PartiesManage->value);
        $this->role = PartyRole::from($role)->value;
        $this->source = $source;
    }

    #[On('open-quick-party')]
    public function open(): void
    {
        $this->authorize(Permission::PartiesManage->value);
        $this->resetErrorBag();
        $this->form = [
            'name' => '',
            'name_ur' => '',
            'phone' => '',
            'opening_amount' => '',
            'opening_direction' => $this->partyRole() === PartyRole::Customer ? SaveParty::THEY_OWE_US : SaveParty::WE_OWE_THEM,
        ];
        $this->formKey = (string) Str::uuid();
        $this->show = true;
    }

    /**
     * Existing parties with a similar name, to avoid entering the same person twice.
     *
     * @return Collection<int, Party>
     */
    #[Computed]
    public function similarParties(): Collection
    {
        $name = trim($this->form['name'] ?? '');
        if (mb_strlen($name) < 3) {
            return collect();
        }

        return Party::query()
            ->where(fn (Builder $q) => $q->where('name', 'like', "%{$name}%")->orWhere('name_ur', 'like', "%{$name}%"))
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    public function save(SaveParty $saveParty): void
    {
        $name = trim($this->form['name'] ?? '');
        // The same name again is almost always the same person: choose them from the list instead.
        if ($name !== '' && Party::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['form.name' => __('":name" already exists. Choose them from the list, or add the role on the Parties page.', ['name' => $name])]);
        }

        $party = $this->runAction(fn () => $saveParty->handle($this->actor(), null, [
            'roles' => [$this->role],
            'name' => $name,
            'name_ur' => $this->form['name_ur'] ?? '',
            'phone' => $this->form['phone'] ?? '',
            'phone_2' => '',
            'address' => '',
            'area_id' => '',
            'sales_agent_id' => '',
            'notes' => '',
            'opening_amount' => trim($this->form['opening_amount'] ?? '') === '' ? '0' : $this->form['opening_amount'],
            'opening_direction' => $this->form['opening_direction'] ?? '',
            'opening_date' => now()->format('Y-m-d'),
        ], $this->formKey), 'form');

        $this->show = false;
        $this->dispatch('combobox-add', source: $this->source, option: [
            'value' => $party->id,
            'label' => $party->displayName(),
            'hint' => $this->partyRole() === PartyRole::Customer ? (string) $party->phone : $this->partyRole()->label(),
            'search' => mb_strtolower(implode(' ', array_filter([$party->name, $party->name_ur, $party->phone]))),
        ]);
        $this->dispatch('quick-party-added', role: $this->role, id: $party->id);
        $this->toast($this->partyRole() === PartyRole::Customer ? __('Customer added.') : __('Supplier added.'));
    }

    public function partyRole(): PartyRole
    {
        return PartyRole::from($this->role);
    }

    public function render(): View
    {
        return view('livewire.parties.quick-add-party');
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
