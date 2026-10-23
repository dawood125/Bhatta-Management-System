<?php

namespace App\Livewire\Sales;

use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Models\SaleDispatch;
use App\Models\Unit;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Deliveries (trips) in a period: which bricks left for which customer, by which vehicle and
 * driver, and the carriage.
 *
 * @property-read LengthAwarePaginator<int, SaleDispatch>|null $dispatches Livewire computed property
 * @property-read array{trips: int, bricks: Quantity, carriage: Money} $summary Livewire computed property
 */
class DispatchIndex extends Component
{
    use FiltersByDateRange, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::SalesView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, SaleDispatch>|null
     */
    #[Computed]
    public function dispatches(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['sale.customer', 'sale.lines.product.unit', 'lines', 'driver'])
            ->orderByDesc('dispatch_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Trips, bricks delivered and carriage in the period (reversed deliveries left out).
     *
     * @return array{trips: int, bricks: Quantity, carriage: Money}
     */
    #[Computed]
    public function summary(): array
    {
        $ids = $this->filtered()?->whereNull('reversed_at')->pluck('id') ?? collect();

        $bricks = DB::table('sale_dispatch_lines as dl')
            ->join('sale_lines as l', 'l.id', '=', 'dl.sale_line_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->whereIn('dl.sale_dispatch_id', $ids)
            ->where('u.code', Unit::BRICK)
            ->sum('dl.quantity');

        return [
            'trips' => $ids->count(),
            'bricks' => Quantity::fromMilli((int) $bricks),
            'carriage' => Money::fromPaisa((int) SaleDispatch::query()->whereKey($ids)->sum('carriage')),
        ];
    }

    public function render(): View
    {
        return view('livewire.sales.dispatch-index', [
            'range' => $this->dateRange(),
        ])->title(__('Deliveries'));
    }

    /**
     * @return Builder<SaleDispatch>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);

        return SaleDispatch::query()
            ->whereBetween('dispatch_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('vehicle', 'like', "%{$search}%")
                ->orWhereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%"))
                ->orWhereHas('sale', fn (Builder $s) => $s->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%")))));
    }
}
