<?php

namespace App\Livewire\Sales;

use App\Accounting\LedgerQueries;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\Party;
use App\Models\SaleDispatch;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Drivers due (D-013): the trips of a day per driver with their carriage, and what each driver is
 * owed in total now. "Pay" opens a payment voucher with the drivers and amounts filled in.
 *
 * @property-read Collection<int, array{driver: Party, trips: int, carriage: Money, owed: Money}> $rows Livewire computed property
 */
class DriversDue extends Component
{
    #[Url(except: '')]
    public string $date = '';

    public function mount(): void
    {
        $this->authorize(Permission::SalesView->value);
        $this->date = $this->day()->format('Y-m-d');
    }

    public function day(): Carbon
    {
        try {
            return $this->date !== '' ? Carbon::createFromFormat('Y-m-d', $this->date)->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    /**
     * Every driver with a trip that day or carriage still owed to them.
     *
     * @return Collection<int, array{driver: Party, trips: int, carriage: Money, owed: Money}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $trips = SaleDispatch::query()
            ->whereNull('reversed_at')
            ->whereNotNull('driver_id')
            ->where('dispatch_date', $this->day()->format('Y-m-d'))
            ->get(['driver_id', 'carriage'])
            ->groupBy('driver_id');

        $drivers = Party::query()->withRole(PartyRole::Driver)->orderBy('name')->get();
        $balances = app(LedgerQueries::class)->partyBalances($drivers->pluck('id')->all());

        return $drivers->toBase()
            ->map(function (Party $driver) use ($trips, $balances) {
                $own = $trips->get($driver->id, collect());
                $balance = $balances[$driver->id] ?? Money::zero();

                return [
                    'driver' => $driver,
                    'trips' => $own->count(),
                    'carriage' => Money::sum($own->map(fn (SaleDispatch $trip) => $trip->carriage)),
                    // A negative balance is what the kiln owes the driver.
                    'owed' => $balance->isNegative() ? $balance->negate() : Money::zero(),
                ];
            })
            ->filter(fn (array $row) => $row['trips'] > 0 || $row['owed']->isPositive())
            ->values();
    }

    /**
     * Link to a payment voucher for the given drivers, filled in with what each is owed.
     *
     * @param  Collection<int, array{driver: Party, trips: int, carriage: Money, owed: Money}>  $rows
     */
    public function payLink(Collection $rows): ?string
    {
        $pairs = $rows->filter(fn (array $row) => $row['owed']->isPositive())
            ->map(fn (array $row) => $row['driver']->id.':'.$row['owed']->toDecimal())
            ->implode(',');

        return $pairs === '' ? null : route('vouchers.create', ['kind' => 'payment', 'lines' => $pairs]);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.sales.drivers-due', [
            'canPay' => $actor->can(Permission::PaymentsRecord->value),
            'totalOwed' => Money::sum($this->rows->map(fn (array $row) => $row['owed'])),
        ])->title(__('Drivers due'));
    }
}
