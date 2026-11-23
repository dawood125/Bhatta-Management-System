<?php

namespace App\Livewire\Sales;

use App\Actions\Trolleys\RecordTrolleyCost;
use App\Actions\Trolleys\SaveTrolley;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\Trolley;
use App\Models\TrolleyCost;
use App\Models\User;
use App\Sales\DriverSettlementSheet;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The monthly driver settlement for the kiln's own trolleys: carriage earned, less diesel and
 * maintenance, and what is left to pay each driver. Trolleys and their costs are recorded here.
 *
 * @property-read list<array{driver: Party, trolleys: string, trips: int, brought_forward: Money, carriage: Money, diesel: Money, maintenance: Money, other: Money, paid: Money, closing: Money, payable: Money}> $rows Livewire computed property
 * @property-read Collection<int, Trolley> $trolleys Livewire computed property
 * @property-read Collection<int, TrolleyCost> $costs Livewire computed property
 */
class DriverSettlement extends Component
{
    use RunsActions;

    #[Url(except: '')]
    public string $month = '';

    public bool $showCost = false;

    public bool $showTrolley = false;

    public ?int $editingTrolley = null;

    public string $costKey = '';

    /** @var array<string, mixed> */
    public array $costForm = [];

    /** @var array<string, mixed> */
    public array $trolleyForm = [];

    public function mount(): void
    {
        $this->authorize(Permission::SalesView->value);
        $this->month = $this->monthStart()->format('Y-m');
    }

    public function monthStart(): Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1) {
            return Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();
        }

        return now()->startOfMonth();
    }

    public function updatedMonth(): void
    {
        unset($this->rows, $this->costs);
    }

    /**
     * @return list<array{driver: Party, trolleys: string, trips: int, brought_forward: Money, carriage: Money, diesel: Money, maintenance: Money, other: Money, paid: Money, closing: Money, payable: Money}>
     */
    #[Computed]
    public function rows(): array
    {
        return app(DriverSettlementSheet::class)->forMonth($this->monthStart());
    }

    /**
     * @return Collection<int, Trolley>
     */
    #[Computed]
    public function trolleys(): Collection
    {
        return Trolley::query()->with('driver')->orderByDesc('is_active')->orderBy('number')->get()->toBase();
    }

    /**
     * The month's trolley costs, newest first.
     *
     * @return Collection<int, TrolleyCost>
     */
    #[Computed]
    public function costs(): Collection
    {
        $from = $this->monthStart();

        return TrolleyCost::query()
            ->with(['entry', 'trolley', 'driver'])
            ->whereBetween('cost_date', [$from->format('Y-m-d'), $from->copy()->endOfMonth()->format('Y-m-d')])
            ->orderByDesc('cost_date')
            ->orderByDesc('id')
            ->get()
            ->toBase();
    }

    public function openCost(): void
    {
        $this->authorize(Permission::ExpensesRecord->value);
        $this->resetErrorBag();
        $this->costForm = [
            'trolley_id' => '',
            'cost_date' => now()->format('Y-m-d'),
            'kind' => TrolleyCost::DIESEL,
            'amount' => '',
            'paid_from' => RecordTrolleyCost::FROM_ACCOUNT,
            'account_id' => '',
            'supplier_id' => '',
            'description' => '',
        ];
        $this->costKey = (string) Str::uuid();
        $this->showCost = true;
    }

    public function saveCost(RecordTrolleyCost $record): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $cost = $this->runAction(fn () => $record->handle($actor, $this->costForm, $this->costKey), 'costForm');

        $this->showCost = false;
        unset($this->rows, $this->costs);
        $this->toast(__(':number recorded.', ['number' => $cost->entry->number]));
    }

    public function openTrolley(?int $id = null): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $this->resetErrorBag();
        $trolley = $id !== null ? Trolley::query()->findOrFail($id) : null;
        $this->editingTrolley = $trolley?->id;
        $this->trolleyForm = [
            'number' => $trolley->number ?? '',
            'driver_id' => (string) ($trolley->driver_id ?? ''),
            'is_active' => $trolley->is_active ?? true,
        ];
        $this->showTrolley = true;
    }

    public function saveTrolley(SaveTrolley $save): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $trolley = $this->editingTrolley !== null ? Trolley::query()->findOrFail($this->editingTrolley) : null;
        $this->runAction(fn () => $save->handle($actor, $trolley, $this->trolleyForm), 'trolleyForm');

        $this->showTrolley = false;
        unset($this->rows, $this->trolleys);
        $this->toast(__('Trolley saved.'));
    }

    /**
     * Link to a payment voucher filled in with what is payable to the given drivers.
     *
     * @param  list<array{driver: Party, payable: Money}>  $rows
     */
    public function payLink(array $rows): ?string
    {
        $pairs = collect($rows)
            ->filter(fn (array $row) => $row['payable']->isPositive())
            ->map(fn (array $row) => $row['driver']->id.':'.$row['payable']->toDecimal())
            ->implode(',');

        return $pairs === '' ? null : route('vouchers.create', ['kind' => 'payment', 'lines' => $pairs]);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.sales.driver-settlement', [
            'canPay' => $actor->can(Permission::PaymentsRecord->value),
            'canCost' => $actor->can(Permission::ExpensesRecord->value),
            'canManage' => $actor->can(Permission::SettingsManage->value),
            'accountOptions' => $this->showCost ? TreasuryAccount::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (TreasuryAccount $a) => [$a->id => $a->displayName()])->all() : [],
            'supplierOptions' => $this->showCost ? Party::query()->withRole(PartyRole::Supplier)->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Party $p) => [$p->id => $p->displayName()])->all() : [],
            'driverOptions' => $this->showTrolley ? Party::query()->withRole(PartyRole::Driver)->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Party $p) => [$p->id => $p->displayName()])->all() : [],
            'trolleyOptions' => $this->trolleys->where('is_active', true)->mapWithKeys(fn (Trolley $t) => [$t->id => $t->number.' · '.$t->driver->displayName()])->all(),
        ])->title(__('Driver settlement'));
    }
}
