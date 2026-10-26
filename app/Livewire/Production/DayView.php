<?php

namespace App\Livewire\Production;

use App\Enums\Permission;
use App\Enums\StageKind;
use App\Livewire\Concerns\ReversesProductionEntries;
use App\Livewire\Concerns\RunsActions;
use App\Models\Product;
use App\Models\ProductionEntry;
use App\Models\ProductionStage;
use App\Models\SaleDispatch;
use App\Production\BrickStock;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One day of production: each stage's (and grade's) stock at the start and end of the day,
 * what came in and went out, the day's entries, Pathai totals, and entries worth a second look
 * (possible duplicates, stock adjustments). Printable.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SaleDispatch> $dispatches Livewire computed property
 * @property-read array{slots: list<array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}>, entries: Collection<int, ProductionEntry>, pathai: array{made: Quantity, rejected: Quantity, accepted: Quantity}, duplicates: list<int>}|null $report Livewire computed property
 */
class DayView extends Component
{
    use ReversesProductionEntries, RunsActions;

    #[Url]
    public string $date = '';

    public function mount(): void
    {
        $this->authorize(Permission::ProductionView->value);
        $this->date = $this->validDay($this->date) ?? now()->format('Y-m-d');
    }

    public function previousDay(): void
    {
        $this->date = Carbon::createFromFormat('Y-m-d', $this->validDay($this->date) ?? now()->format('Y-m-d'))->subDay()->format('Y-m-d');
    }

    public function nextDay(): void
    {
        $next = Carbon::createFromFormat('Y-m-d', $this->validDay($this->date) ?? now()->format('Y-m-d'))->addDay();
        $this->date = $next->isAfter(now()) ? now()->format('Y-m-d') : $next->format('Y-m-d');
    }

    /**
     * @return array{slots: list<array{stage: StageKind, product_id: int|null, opening: Quantity, in: Quantity, out: Quantity, closing: Quantity}>, entries: Collection<int, ProductionEntry>, pathai: array{made: Quantity, rejected: Quantity, accepted: Quantity}, duplicates: list<int>}|null
     */
    #[Computed]
    public function report(): ?array
    {
        $day = $this->validDay($this->date);

        return $day === null ? null : app(BrickStock::class)->day(Carbon::createFromFormat('Y-m-d', $day)->startOfDay());
    }

    /**
     * Bricks delivered to customers that day (they leave "ready for sale" stock too).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SaleDispatch>
     */
    #[Computed]
    public function dispatches(): \Illuminate\Database\Eloquent\Collection
    {
        $day = $this->validDay($this->date);

        return SaleDispatch::query()
            ->where('dispatch_date', $day ?? '0000-00-00')
            ->with(['sale.customer', 'sale.lines.product.unit', 'lines', 'creator'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()->with('unit')->get()->keyBy('id');
    }

    /**
     * @return Collection<string, ProductionStage>
     */
    #[Computed]
    public function stages(): Collection
    {
        return ProductionStage::query()->orderBy('sort_order')->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);
    }

    #[On('production-recorded')]
    public function refresh(): void
    {
        unset($this->report);
    }

    protected function afterReverse(): void
    {
        unset($this->report);
    }

    public function render(): View
    {
        return view('livewire.production.day-view')->title(__('Daily production'));
    }

    private function validDay(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }
}
