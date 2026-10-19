<?php

namespace App\Livewire\Production;

use App\Actions\Production\RecordBrickAdjustment;
use App\Actions\Production\RecordFiring;
use App\Actions\Production\RecordLoading;
use App\Actions\Production\RecordNakaasi;
use App\Actions\Production\RecordPathai;
use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Enums\ProductKind;
use App\Enums\StageKind;
use App\Livewire\Concerns\RunsActions;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\Unit;
use App\Models\User;
use App\Production\BrickStock;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The dialogs that record production: Pathai, loading into the kiln, firing (with fuel), Nakaasi
 * and stock adjustments. Placed on every production page; opened with the browser event
 * "open-production-form" ({kind}). After saving it announces "production-recorded".
 *
 * @property-read array{raw: Quantity, in_firing: Quantity, fired_awaiting_unloading: Quantity, saleable: array<int, Quantity>} $levels Livewire computed property
 * @property-read Collection<int, Product> $gradeProducts Livewire computed property
 * @property-read Collection<int, Material> $fuelMaterials Livewire computed property
 * @property-read Collection<string, ProductionStage> $stages Livewire computed property
 */
class EntryForms extends Component
{
    use RunsActions;

    public bool $showForm = false;

    public string $kind = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public string $formKey = '';

    #[On('open-production-form')]
    public function open(string $kind): void
    {
        $type = ProductionEntryKind::tryFrom($kind);
        abort_if($type === null, 404);
        $this->authorize(($type === ProductionEntryKind::Adjustment ? Permission::ProductionAdjust : Permission::ProductionRecord)->value);

        $this->resetErrorBag();
        $this->kind = $type->value;
        $today = now()->format('Y-m-d');

        $this->form = match ($type) {
            ProductionEntryKind::Pathai => ['entry_date' => $today, 'team' => '', 'made' => '', 'rejected' => '', 'notes' => ''],
            ProductionEntryKind::Loading => ['entry_date' => $today, 'quantity' => '', 'notes' => ''],
            ProductionEntryKind::Firing => ['entry_date' => $today, 'quantity' => '', 'notes' => '', 'fuel' => []],
            ProductionEntryKind::Nakaasi => [
                'entry_date' => $today,
                'notes' => '',
                'lines' => $this->gradeProducts->map(fn (Product $p) => ['product_id' => (string) $p->id, 'quantity' => ''])->values()->all(),
            ],
            ProductionEntryKind::Adjustment => ['entry_date' => $today, 'stage' => StageKind::Saleable->value, 'product_id' => '', 'direction' => RecordBrickAdjustment::REMOVE, 'quantity' => '', 'reason' => ''],
        };

        unset($this->levels);
        $this->formKey = (string) Str::uuid();
        $this->showForm = true;
    }

    public function updated(string $property): void
    {
        // A field the person has just changed no longer shows its old error.
        $this->resetErrorBag($property);
    }

    /**
     * @return array{raw: Quantity, in_firing: Quantity, fired_awaiting_unloading: Quantity, saleable: array<int, Quantity>}
     */
    #[Computed]
    public function levels(): array
    {
        return app(BrickStock::class)->levels();
    }

    /**
     * Grades and by-products that come out of Nakaasi: bricks first, then other units.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function gradeProducts(): Collection
    {
        return Product::query()
            ->with('unit')
            ->where('is_active', true)
            ->whereIn('kind', [ProductKind::FiredGrade, ProductKind::ByProduct])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Product $p) => $p->unit->code === Unit::BRICK ? 0 : 1)
            ->values();
    }

    /**
     * Active materials for the fuel lines, fuels first.
     *
     * @return Collection<int, Material>
     */
    #[Computed]
    public function fuelMaterials(): Collection
    {
        return Material::query()
            ->with('unit')
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN kind = ? THEN 0 ELSE 1 END', [MaterialKind::Fuel->value])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @return Collection<string, ProductionStage>
     */
    #[Computed]
    public function stages(): Collection
    {
        return ProductionStage::query()->orderBy('sort_order')->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);
    }

    /** Total bricks typed in the Nakaasi form; null while one of them is not a whole number. */
    public function nakaasiTotal(): ?Quantity
    {
        $total = Quantity::zero();
        $products = $this->gradeProducts->keyBy('id');

        foreach ($this->form['lines'] ?? [] as $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));
            $text = trim((string) ($line['quantity'] ?? ''));
            if ($product === null || $text === '' || $product->unit->code !== Unit::BRICK) {
                continue;
            }

            try {
                $total = $total->add(Quantity::fromInput($text, 0));
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $total;
    }

    public function addFuel(): void
    {
        if (count($this->form['fuel'] ?? []) < RecordFiring::MAX_FUEL_LINES) {
            $first = $this->fuelMaterials->first();
            $this->form['fuel'][] = ['key' => (string) Str::uuid(), 'material_id' => $first !== null && $first->kind === MaterialKind::Fuel ? (string) $first->id : '', 'quantity' => ''];
        }
    }

    public function removeFuel(int $index): void
    {
        unset($this->form['fuel'][$index]);
        $this->form['fuel'] = array_values($this->form['fuel']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $entry = $this->runAction(fn () => match (ProductionEntryKind::from($this->kind)) {
            ProductionEntryKind::Pathai => app(RecordPathai::class)->handle($actor, $this->form, $this->formKey),
            ProductionEntryKind::Loading => app(RecordLoading::class)->handle($actor, $this->form, $this->formKey),
            ProductionEntryKind::Firing => app(RecordFiring::class)->handle($actor, $this->form, $this->formKey),
            ProductionEntryKind::Nakaasi => app(RecordNakaasi::class)->handle($actor, $this->form, $this->formKey),
            ProductionEntryKind::Adjustment => app(RecordBrickAdjustment::class)->handle($actor, $this->form, $this->formKey),
        }, 'form');

        $this->showForm = false;
        $this->toast(__(':number recorded.', ['number' => $entry->number]));
        $this->dispatch('production-recorded');
    }

    public function render(): View
    {
        return view('livewire.production.entry-forms', [
            'type' => ProductionEntryKind::tryFrom($this->kind),
        ]);
    }
}
