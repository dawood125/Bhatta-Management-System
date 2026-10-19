<?php

namespace App\Livewire\Materials;

use App\Accounting\LedgerQueries;
use App\Actions\Materials\RecordMaterialPurchase;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\PurchaseChargeKind;
use App\Livewire\Concerns\RunsActions;
use App\Materials\MaterialStock;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\MaterialPurchaseCharge;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A new material purchase: supplier and how it is paid, the materials with quantity and rate,
 * and extra charges (trolley, excavator, ...) that are added to the materials' cost.
 *
 * @property-read Collection<int, Material> $materials Livewire computed property
 * @property-read Collection<int, TreasuryAccount> $accounts Livewire computed property
 * @property-read array<int, Money|null> $lineAmounts Livewire computed property
 * @property-read Money $goodsTotal Livewire computed property
 * @property-read Money|null $chargesTotal Livewire computed property
 * @property-read Money $totalCost Livewire computed property
 * @property-read array<int, Money|null> $landedPerUnit Livewire computed property
 * @property-read Money|null $supplierBalance Livewire computed property
 */
class PurchaseForm extends Component
{
    use RunsActions;

    /** @var array<string, mixed> */
    public array $form = [];

    /** One-time key of this purchase: a double submit can never post twice. */
    public string $formKey = '';

    /** The party list is sent with the first render only (it can be long); the page keeps it. */
    public bool $optionsSent = false;

    public function mount(): void
    {
        $this->authorize(Permission::MaterialsRecord->value);

        $this->form = [
            'purchase_date' => now()->format('Y-m-d'),
            'payment' => MaterialPurchase::ON_CREDIT,
            'supplier_id' => '',
            'account_id' => (string) ($this->accounts->first()->id ?? ''),
            'reference' => '',
            'notes' => '',
            'lines' => [$this->blankLine()],
            'charges' => [],
        ];
        $this->formKey = (string) Str::uuid();
    }

    public function rendered(): void
    {
        $this->optionsSent = true;
    }

    /**
     * @return Collection<int, Material>
     */
    #[Computed]
    public function materials(): Collection
    {
        return Material::query()->with('unit')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * @return Collection<int, TreasuryAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return TreasuryAccount::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * Searchable list of active parties (suppliers first) for the supplier and charge pickers.
     *
     * @return list<array{value: int, label: string, hint: string, search: string}>
     */
    public function partyOptions(): array
    {
        $parties = Party::query()->where('is_active', true)->orderBy('name')->get();
        $roles = DB::table('party_roles')->whereIn('party_id', $parties->pluck('id'))->get()->groupBy('party_id');

        return $parties
            ->map(function (Party $party) use ($roles) {
                $partyRoles = collect($roles[$party->id] ?? [])->map(fn ($row) => PartyRole::from($row->role));

                return [
                    'value' => $party->id,
                    'label' => $party->displayName(),
                    'hint' => $partyRoles->map(fn (PartyRole $r) => $r->label())->sort()->implode(', '),
                    'search' => mb_strtolower(implode(' ', array_filter([$party->name, $party->name_ur, $party->phone, $party->phone_2]))),
                    'supplier' => $partyRoles->contains(PartyRole::Supplier),
                ];
            })
            ->sortByDesc('supplier')
            ->map(fn (array $option) => array_diff_key($option, ['supplier' => true]))
            ->values()
            ->all();
    }

    /** The chosen supplier's balance (for the "Balance now" hint). */
    #[Computed]
    public function supplierBalance(): ?Money
    {
        $id = (string) ($this->form['supplier_id'] ?? '');

        return ctype_digit($id) ? app(LedgerQueries::class)->partyBalances([(int) $id])[(int) $id] : null;
    }

    /**
     * Amount of each line (quantity × rate) while it can be worked out.
     *
     * @return array<int, Money|null>
     */
    #[Computed]
    public function lineAmounts(): array
    {
        $amounts = [];
        foreach ($this->rows('lines') as $index => $line) {
            $material = ctype_digit((string) ($line['material_id'] ?? '')) ? $this->materials->get((int) $line['material_id']) : null;

            try {
                $quantity = Quantity::fromInput((string) ($line['quantity'] ?? ''), $material !== null ? $material->unit->decimals : 3);
                $amounts[$index] = Money::fromRupees((string) ($line['rate'] ?? ''))->timesQuantity($quantity->toDecimal());
            } catch (InvalidArgumentException) {
                $amounts[$index] = null;
            }
        }

        return $amounts;
    }

    /** Total of the lines that are complete so far (saving checks an impossible total properly). */
    #[Computed]
    public function goodsTotal(): Money
    {
        try {
            return Money::sum(array_filter($this->lineAmounts));
        } catch (InvalidArgumentException) {
            return Money::zero();
        }
    }

    #[Computed]
    public function chargesTotal(): ?Money
    {
        $total = Money::zero();
        foreach ($this->rows('charges') as $charge) {
            try {
                $total = $total->add(Money::fromRupees((string) ($charge['amount'] ?? '')));
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $total;
    }

    #[Computed]
    public function totalCost(): Money
    {
        try {
            return $this->goodsTotal->add($this->chargesTotal ?? Money::zero());
        } catch (InvalidArgumentException) {
            return Money::zero();
        }
    }

    /**
     * Landed cost per unit of each line (its amount plus its share of the charges, per unit).
     *
     * @return array<int, Money|null>
     */
    #[Computed]
    public function landedPerUnit(): array
    {
        $amounts = $this->lineAmounts;
        if ($this->chargesTotal === null) {
            return array_map(fn () => null, $amounts);
        }

        $shares = app(RecordMaterialPurchase::class)->allocate($this->chargesTotal, array_map(fn (?Money $m) => $m ?? Money::zero(), $amounts));
        $result = [];
        foreach ($this->rows('lines') as $index => $line) {
            $material = ctype_digit((string) ($line['material_id'] ?? '')) ? $this->materials->get((int) $line['material_id']) : null;

            try {
                $quantity = Quantity::fromInput((string) ($line['quantity'] ?? ''), $material !== null ? $material->unit->decimals : 3);
            } catch (InvalidArgumentException) {
                $result[$index] = null;

                continue;
            }

            $cost = ($amounts[$index] ?? Money::zero())->add($shares[$index]);
            $result[$index] = $amounts[$index] !== null ? app(MaterialStock::class)->averageCost(['quantity' => $quantity, 'value' => $cost]) : null;
        }

        return $result;
    }

    public function addLine(): void
    {
        if (count($this->form['lines']) < RecordMaterialPurchase::MAX_LINES) {
            $this->form['lines'][] = $this->blankLine();
        }
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
        if ($this->form['lines'] === []) {
            $this->form['lines'][] = $this->blankLine();
        }
        $this->resetErrorBag();
    }

    public function addCharge(): void
    {
        if (count($this->form['charges']) < RecordMaterialPurchase::MAX_CHARGES) {
            $this->form['charges'][] = [
                'key' => (string) Str::uuid(),
                'kind' => PurchaseChargeKind::Transport->value,
                'description' => '',
                'amount' => '',
                'payee' => MaterialPurchaseCharge::WITH_GOODS,
                'party_id' => '',
                'account_id' => '',
            ];
        }
    }

    public function removeCharge(int $index): void
    {
        unset($this->form['charges'][$index]);
        $this->form['charges'] = array_values($this->form['charges']);
        $this->resetErrorBag();
    }

    public function updated(string $property): void
    {
        // A field the person has just changed no longer shows its old error.
        $this->resetErrorBag($property);
    }

    public function save(RecordMaterialPurchase $recordPurchase): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $purchase = $this->runAction(fn () => $recordPurchase->handle($actor, $this->form, $this->formKey), 'form');

        session()->flash('toast', ['type' => 'success', 'message' => __(':number recorded.', ['number' => $purchase->entry->number])]);
        $this->redirectRoute('materials.purchases.show', ['purchase' => $purchase->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.materials.purchase-form')->title(__('New purchase'));
    }

    /**
     * The form's lines or charges (Livewire state can be anything the browser sends, so check it).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $key): array
    {
        $rows = $this->form[$key] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array{key: string, material_id: string, quantity: string, rate: string}
     */
    private function blankLine(): array
    {
        return ['key' => (string) Str::uuid(), 'material_id' => '', 'quantity' => '', 'rate' => ''];
    }
}
