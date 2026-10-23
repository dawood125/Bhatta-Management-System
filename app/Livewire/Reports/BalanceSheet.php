<?php

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\Unit;
use App\Production\BrickStock;
use App\Reports\FinancialStatements;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the kiln owns, owes and is worth on a date. Assets always equal liabilities plus equity,
 * and the page says so. Bricks in stock are listed in quantity (not valued, Q-42).
 *
 * @property-read array{assets: list<array{label: string, amount: Money, treasury_id?: int}>, total_assets: Money, liabilities: list<array{label: string, amount: Money, treasury_id?: int}>, total_liabilities: Money, equity: list<array{label: string, amount: Money}>, total_equity: Money, balanced: bool}|null $report Livewire computed property
 * @property-read array{raw: Quantity, in_firing: Quantity, awaiting: Quantity, saleable: Quantity}|null $bricks Livewire computed property
 */
class BalanceSheet extends Component
{
    #[Url(as: 'date')]
    public string $asOf = '';

    public function mount(): void
    {
        $this->authorize(Permission::FinancialReportsView->value);

        if ($this->date() === null) {
            $this->asOf = now()->format('Y-m-d');
        }
    }

    /**
     * @return array{assets: list<array{label: string, amount: Money, treasury_id?: int}>, total_assets: Money, liabilities: list<array{label: string, amount: Money, treasury_id?: int}>, total_liabilities: Money, equity: list<array{label: string, amount: Money}>, total_equity: Money, balanced: bool}|null
     */
    #[Computed]
    public function report(): ?array
    {
        $date = $this->date();

        return $date === null ? null : app(FinancialStatements::class)->balanceSheet($date);
    }

    /**
     * Bricks at each stage on the date (ready for sale: products counted in bricks).
     *
     * @return array{raw: Quantity, in_firing: Quantity, awaiting: Quantity, saleable: Quantity}|null
     */
    #[Computed]
    public function bricks(): ?array
    {
        $date = $this->date();
        if ($date === null) {
            return null;
        }

        $levels = app(BrickStock::class)->levels($date);
        $brickProducts = Product::query()->whereHas('unit', fn ($q) => $q->where('code', Unit::BRICK))->pluck('id')->all();
        $saleable = 0;
        foreach ($levels['saleable'] as $productId => $quantity) {
            if (in_array($productId, $brickProducts, true)) {
                $saleable += $quantity->milli;
            }
        }

        return [
            'raw' => $levels['raw'],
            'in_firing' => $levels['in_firing'],
            'awaiting' => $levels['fired_awaiting_unloading'],
            'saleable' => Quantity::fromMilli($saleable),
        ];
    }

    public function render(): View
    {
        $date = $this->date();

        return view('livewire.reports.balance-sheet', [
            'pdfUrl' => $date === null ? null : route('reports.pdf', ['report' => 'balance-sheet', 'date' => $date->format('Y-m-d')]),
        ])->title(__('Balance sheet'));
    }

    private function date(): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->asOf)) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $this->asOf);

        return $date !== null && $date->format('Y-m-d') === $this->asOf ? $date->startOfDay() : null;
    }
}
