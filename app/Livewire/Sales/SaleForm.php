<?php

namespace App\Livewire\Sales;

use App\Accounting\LedgerQueries;
use App\Actions\Sales\RecordSale;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Party;
use App\Models\Product;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Sales\SaleStock;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A new sale: the customer, each grade with quantity and rate, and optionally the bricks taken
 * now (with driver and carriage) and money received now.
 *
 * @property-read Collection<int, Product> $products Livewire computed property
 * @property-read array<int, array{stock: Quantity, reserved: Quantity, free: Quantity}> $availability Livewire computed property
 * @property-read Collection<int, TreasuryAccount> $accounts Livewire computed property
 * @property-read Collection<int, Party> $agents Livewire computed property
 * @property-read Collection<int, Party> $drivers Livewire computed property
 * @property-read array<int, Money|null> $lineAmounts Livewire computed property
 * @property-read Money $goodsTotal Livewire computed property
 * @property-read Money|null $carriage Livewire computed property
 * @property-read Money|null $customerBalance Livewire computed property
 */
class SaleForm extends Component
{
    use RunsActions;

    /** @var array<string, mixed> */
    public array $form = [];

    /** One-time key of this sale: a double submit can never record it twice. */
    public string $formKey = '';

    /** The customer list is sent with the first render only (it can be long); the page keeps it. */
    public bool $optionsSent = false;

    public function mount(): void
    {
        $this->authorize(Permission::SalesRecord->value);

        $this->form = [
            'sale_date' => now()->format('Y-m-d'),
            'customer_id' => '',
            'agent_id' => '',
            'notes' => '',
            'lines' => [$this->blankLine()],
            'deliver_now' => false,
            'driver_id' => '',
            'vehicle' => '',
            'carriage' => '',
            'received' => '',
            'account_id' => (string) ($this->accounts->first()->id ?? ''),
        ];
        $this->formKey = (string) Str::uuid();
    }

    public function rendered(): void
    {
        $this->optionsSent = true;
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()->with('unit')->where('is_active', true)->where('is_saleable', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * In stock, reserved by open sales and free to sell, per product.
     *
     * @return array<int, array{stock: Quantity, reserved: Quantity, free: Quantity}>
     */
    #[Computed]
    public function availability(): array
    {
        return app(SaleStock::class)->availability($this->products);
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
     * @return Collection<int, Party>
     */
    #[Computed]
    public function agents(): Collection
    {
        return Party::query()->withRole(PartyRole::Agent)->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Party>
     */
    #[Computed]
    public function drivers(): Collection
    {
        return Party::query()->withRole(PartyRole::Driver)->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Searchable list of active customers for the customer picker.
     *
     * @return list<array{value: int, label: string, hint: string, search: string}>
     */
    public function customerOptions(): array
    {
        return Party::query()->withRole(PartyRole::Customer)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Party $party) => [
                'value' => $party->id,
                'label' => $party->displayName(),
                'hint' => (string) $party->phone,
                'search' => mb_strtolower(implode(' ', array_filter([$party->name, $party->name_ur, $party->phone, $party->phone_2]))),
            ])
            ->values()
            ->all();
    }

    /** The chosen customer's balance (for the "Balance now" hint). */
    #[Computed]
    public function customerBalance(): ?Money
    {
        $id = (string) ($this->form['customer_id'] ?? '');

        return ctype_digit($id) ? app(LedgerQueries::class)->partyBalances([(int) $id])[(int) $id] : null;
    }

    /**
     * Amount of each line (quantity × rate − discount) while it can be worked out.
     *
     * @return array<int, Money|null>
     */
    #[Computed]
    public function lineAmounts(): array
    {
        $amounts = [];
        foreach ($this->rows() as $index => $line) {
            $product = $this->productOf($line);

            try {
                $quantity = Quantity::fromInput((string) ($line['quantity'] ?? ''), $product !== null ? $product->unit->decimals : 3);
                $per = $product !== null ? RecordSale::ratePer($product) : 1;
                $gross = Money::fromRupees((string) ($line['rate'] ?? ''))->timesQuantity($quantity->toDecimal(), $per);
                $discount = blank($line['discount'] ?? null) ? Money::zero() : Money::fromRupees((string) $line['discount']);
                $amounts[$index] = $gross->subtract($discount);
            } catch (InvalidArgumentException) {
                $amounts[$index] = null;
            }
        }

        return $amounts;
    }

    #[Computed]
    public function goodsTotal(): Money
    {
        try {
            return Money::sum(array_filter($this->lineAmounts));
        } catch (InvalidArgumentException) {
            return Money::zero();
        }
    }

    /** Carriage of a delivery made now (none unless the bricks are taken now). */
    #[Computed]
    public function carriage(): ?Money
    {
        if (! ($this->form['deliver_now'] ?? false) || blank($this->form['carriage'] ?? null)) {
            return Money::zero();
        }

        try {
            return Money::fromRupees((string) $this->form['carriage']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function updatedFormCustomerId(string $value): void
    {
        // Suggest the customer's own sales agent (it can still be changed or removed).
        $customer = ctype_digit($value) ? Party::query()->find((int) $value) : null;
        if ($customer !== null && $customer->sales_agent_id !== null && $this->agents->contains('id', $customer->sales_agent_id)) {
            $this->form['agent_id'] = (string) $customer->sales_agent_id;
        }
    }

    public function addLine(): void
    {
        if (count($this->form['lines']) < RecordSale::MAX_LINES) {
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

    public function updated(string $property): void
    {
        // A field the person has just changed no longer shows its old error.
        $this->resetErrorBag($property);
    }

    public function save(RecordSale $recordSale): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $sale = $this->runAction(fn () => $recordSale->handle($actor, $this->form, $this->formKey), 'form');

        session()->flash('toast', ['type' => 'success', 'message' => __(':number recorded.', ['number' => $sale->number])]);
        $this->redirectRoute('sales.show', ['sale' => $sale->id], navigate: true);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.sales.sale-form', [
            'canReceive' => $actor->can(Permission::PaymentsRecord->value),
        ])->title(__('New sale'));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function productOf(array $line): ?Product
    {
        $id = (string) ($line['product_id'] ?? '');

        return ctype_digit($id) ? $this->products->get((int) $id) : null;
    }

    /**
     * The form's lines (Livewire state can be anything the browser sends, so check it).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = $this->form['lines'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array{key: string, product_id: string, quantity: string, rate: string, discount: string}
     */
    private function blankLine(): array
    {
        return ['key' => (string) Str::uuid(), 'product_id' => '', 'quantity' => '', 'rate' => '', 'discount' => ''];
    }
}
