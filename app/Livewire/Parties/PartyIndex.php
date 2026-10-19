<?php

namespace App\Livewire\Parties;

use App\Accounting\LedgerQueries;
use App\Actions\Parties\SaveParty;
use App\Actions\Parties\SetPartyArchived;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Area;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customers, suppliers, drivers and sales agents with their live balances.
 *
 * @property-read LengthAwarePaginator<int, Party> $parties Livewire computed property
 */
class PartyIndex extends Component
{
    use RunsActions, WithPagination;

    #[Url(except: '')]
    public string $role = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $area = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::PartiesView->value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['role', 'search', 'area', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Party>
     */
    #[Computed]
    public function parties(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Party::query()
            ->with(['area', 'salesAgent'])
            ->when(PartyRole::tryFrom($this->role), fn (Builder $q, PartyRole $role) => $q->withRole($role))
            ->when(ctype_digit($this->area), fn (Builder $q) => $q->where('area_id', (int) $this->area))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ur', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('phone_2', 'like', "%{$search}%")))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'archived', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * Balances of the parties on the current page.
     *
     * @return array<int, Money>
     */
    #[Computed]
    public function balances(): array
    {
        return app(LedgerQueries::class)->partyBalances(collect($this->parties->items())->pluck('id')->all());
    }

    /**
     * Roles of the parties on the current page, in one query.
     *
     * @return array<int, list<PartyRole>>
     */
    #[Computed]
    public function rolesMap(): array
    {
        $map = [];
        foreach (DB::table('party_roles')->whereIn('party_id', collect($this->parties->items())->pluck('id'))->orderBy('role')->get() as $row) {
            $map[(int) $row->party_id][] = PartyRole::from($row->role);
        }

        return $map;
    }

    /**
     * @return array{receivable: Money, payable: Money}
     */
    #[Computed]
    public function totals(): array
    {
        return app(LedgerQueries::class)->partyTotals();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function areaOptions(): array
    {
        $selected = (int) ($this->form['area_id'] ?? 0);

        return Area::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $selected))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Area $area) => [$area->id => $area->displayName()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function agentOptions(): array
    {
        return Party::query()
            ->withRole(PartyRole::Agent)
            ->where('is_active', true)
            ->when($this->editingId, fn (Builder $q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Party $party) => [$party->id => $party->displayName()])
            ->all();
    }

    /**
     * Existing parties with a similar name, to avoid entering the same person twice.
     *
     * @return Collection<int, Party>
     */
    #[Computed]
    public function similarParties(): Collection
    {
        $name = trim((string) ($this->form['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            return collect();
        }

        return Party::query()
            ->where(fn (Builder $q) => $q->where('name', 'like', "%{$name}%")->orWhere('name_ur', 'like', "%{$name}%"))
            ->when($this->editingId, fn (Builder $q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    public function create(): void
    {
        $this->authorize(Permission::PartiesManage->value);
        $this->resetErrorBag();
        $this->editingId = null;
        $role = PartyRole::tryFrom($this->role) ?? PartyRole::Customer;
        $this->form = [
            'roles' => [$role->value],
            'name' => '',
            'name_ur' => '',
            'phone' => '',
            'phone_2' => '',
            'address' => '',
            'area_id' => '',
            'sales_agent_id' => '',
            'notes' => '',
            'opening_amount' => '0',
            'opening_direction' => $role === PartyRole::Customer ? SaveParty::THEY_OWE_US : SaveParty::WE_OWE_THEM,
            'opening_date' => now()->format('Y-m-d'),
        ];
        $this->formKey = (string) Str::uuid();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permission::PartiesManage->value);
        $party = Party::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $party->id;
        $this->form = [
            'roles' => array_map(fn (PartyRole $role) => $role->value, $party->roles()),
            'name' => $party->name,
            'name_ur' => $party->name_ur,
            'phone' => $party->phone,
            'phone_2' => $party->phone_2,
            'address' => $party->address,
            'area_id' => $party->area_id !== null ? (string) $party->area_id : '',
            'sales_agent_id' => $party->sales_agent_id !== null ? (string) $party->sales_agent_id : '',
            'notes' => $party->notes,
            'opening_amount' => $party->opening_balance->abs()->toDecimal(),
            'opening_direction' => $party->opening_balance->isNegative() ? SaveParty::WE_OWE_THEM : SaveParty::THEY_OWE_US,
            'opening_date' => $party->opening_date->format('Y-m-d'),
        ];
        $this->formKey = (string) Str::uuid();
        $this->showForm = true;
    }

    public function save(SaveParty $saveParty): void
    {
        $party = $this->editingId !== null ? Party::query()->findOrFail($this->editingId) : null;

        $this->runAction(fn () => $saveParty->handle($this->actor(), $party, $this->form, $this->formKey), 'form');

        $this->showForm = false;
        $this->toast($party ? __('Changes saved.') : __('Party added.'));
        $this->refreshLists();
    }

    public function archive(int $id, SetPartyArchived $setArchived): void
    {
        $this->setArchived($id, true, $setArchived);
    }

    public function restore(int $id, SetPartyArchived $setArchived): void
    {
        $this->setArchived($id, false, $setArchived);
    }

    public function render(): View
    {
        return view('livewire.parties.party-index', [
            'canManage' => $this->actor()->can(Permission::PartiesManage->value),
        ])->title(__('Parties'));
    }

    private function setArchived(int $id, bool $archived, SetPartyArchived $setArchived): void
    {
        $party = Party::query()->findOrFail($id);

        try {
            $setArchived->handle($this->actor(), $party, $archived);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($archived ? __('Archived. It no longer appears in new entries.') : __('Restored.'));
        $this->refreshLists();
    }

    private function refreshLists(): void
    {
        unset($this->parties, $this->balances, $this->rolesMap, $this->totals, $this->agentOptions);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
