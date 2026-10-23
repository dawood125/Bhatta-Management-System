<?php

namespace App\Livewire\Sales;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Models\Sale;
use App\Models\User;
use App\Sales\CustomerPayments;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sales in a period, newest first, with what each customer has paid of them and whether the
 * bricks were delivered.
 *
 * @property-read LengthAwarePaginator<int, Sale>|null $sales Livewire computed property
 * @property-read array<int, array{due: Money, paid: Money, outstanding: Money, status: PaymentStatus|null}> $payments Livewire computed property
 * @property-read array{total: Money, outstanding: Money, open: int} $summary Livewire computed property
 */
class SaleIndex extends Component
{
    use FiltersByDateRange, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $payment = '';

    public function mount(): void
    {
        $this->authorize(Permission::SalesView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'payment', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Sale>|null
     */
    #[Computed]
    public function sales(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['customer', 'agent', 'lines.product.unit'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Payment state of the sales on this page.
     *
     * @return array<int, array{due: Money, paid: Money, outstanding: Money, status: PaymentStatus|null}>
     */
    #[Computed]
    public function payments(): array
    {
        $customerIds = collect($this->sales?->items() ?? [])->pluck('customer_id')->unique()->values()->all();

        return app(CustomerPayments::class)->forCustomers($customerIds)['sales'];
    }

    /**
     * Totals of the period (with the filters): sold, still to be paid, and sales waiting for bricks.
     *
     * @return array{total: Money, outstanding: Money, open: int}
     */
    #[Computed]
    public function summary(): array
    {
        $sales = $this->filtered()?->where('status', '!=', SaleStatus::Cancelled->value)->get(['id', 'customer_id', 'total', 'status']) ?? collect();
        $payments = app(CustomerPayments::class)->forCustomers($sales->pluck('customer_id')->unique()->values()->all())['sales'];

        return [
            'total' => Money::sum($sales->map(fn (Sale $sale) => $payments[$sale->id]['due'] ?? $sale->total)),
            'outstanding' => Money::sum($sales->map(fn (Sale $sale) => $payments[$sale->id]['outstanding'] ?? Money::zero())),
            'open' => $sales->where('status', SaleStatus::Open)->count(),
        ];
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.sales.sale-index', [
            'canRecord' => $actor->can(Permission::SalesRecord->value),
            'range' => $this->dateRange(),
        ])->title(__('Sales'));
    }

    /**
     * @return Builder<Sale>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);
        $query = Sale::query()
            ->whereBetween('sale_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when(SaleStatus::tryFrom($this->status), fn (Builder $q, SaleStatus $status) => $q->where('status', $status->value))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))));

        // Paid / unpaid comes from each customer's account, not from a column: work it out for the
        // period's sales and keep the ones that match.
        $payment = PaymentStatus::tryFrom($this->payment);
        if ($payment !== null) {
            $rows = (clone $query)->where('status', '!=', SaleStatus::Cancelled->value)->get(['id', 'customer_id']);
            $states = app(CustomerPayments::class)->forCustomers($rows->pluck('customer_id')->unique()->values()->all())['sales'];
            $ids = $rows->filter(fn (Sale $sale) => ($states[$sale->id]['status'] ?? null) === $payment)->pluck('id')->all();
            $query->whereKey($ids);
        }

        return $query;
    }
}
