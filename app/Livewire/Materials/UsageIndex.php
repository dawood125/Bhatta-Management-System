<?php

namespace App\Livewire\Materials;

use App\Actions\Materials\RecordMaterialUsage;
use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\ReversesMaterialEntries;
use App\Livewire\Concerns\RunsActions;
use App\Materials\MaterialStock;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\MaterialUsage;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Materials used, per entry and per period, with their cost; and the form to record usage.
 *
 * @property-read LengthAwarePaginator<int, MaterialUsage>|null $usages Livewire computed property
 * @property-read Collection<int, Material> $activeMaterials Livewire computed property
 * @property-read array<int, array{quantity: Quantity, value: Money}> $levels Livewire computed property
 * @property-read Money $totalCost Livewire computed property
 */
class UsageIndex extends Component
{
    use FiltersByDateRange, ReversesMaterialEntries, RunsActions, WithPagination;

    #[Url(except: '')]
    public string $material = '';

    public bool $showForm = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $formKey = '';

    public function mount(): void
    {
        $this->authorize(Permission::MaterialsView->value);
        $this->initDateRange();

        // Opened from "Record usage" on the stock page.
        if (request()->boolean('new') && auth()->user()?->can(Permission::MaterialsRecord->value)) {
            $this->create();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['material', 'from', 'to'], true)) {
            $this->resetPage();
        }

        if (str_starts_with($property, 'form.')) {
            $this->resetErrorBag($property);
        }
    }

    /**
     * @return LengthAwarePaginator<int, MaterialUsage>|null
     */
    #[Computed]
    public function usages(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['entry', 'creator', 'movements.material.unit', 'productionEntry'])
            ->orderByDesc('usage_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** Cost of the filtered usage that still counts (reversed entries are left out). */
    #[Computed]
    public function totalCost(): Money
    {
        $range = $this->dateRange();
        if ($range === null) {
            return Money::zero();
        }

        $material = ctype_digit($this->material) ? (int) $this->material : null;

        // With a material filter, only that material's share of each entry counts.
        $paisa = DB::table('material_movements as m')
            ->join('material_usages as u', 'u.id', '=', 'm.material_usage_id')
            ->join('journal_entries as e', 'e.id', '=', 'u.journal_entry_id')
            ->where('u.kiln_id', app(CurrentKiln::class)->id())
            ->whereNull('m.reversal_of_id')
            ->where('e.status', JournalEntry::STATUS_POSTED)
            ->whereBetween('u.usage_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when($material, fn ($q, int $id) => $q->where('m.material_id', $id))
            ->sum('m.value_out');

        return Money::fromPaisa((int) $paisa);
    }

    /**
     * @return Collection<int, Material>
     */
    #[Computed]
    public function activeMaterials(): Collection
    {
        return Material::query()->with('unit')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function materialOptions(): array
    {
        return Material::query()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (Material $m) => [$m->id => $m->displayName()])->all();
    }

    /**
     * Stock of the active materials, for the form's hints.
     *
     * @return array<int, array{quantity: Quantity, value: Money}>
     */
    #[Computed]
    public function levels(): array
    {
        return app(MaterialStock::class)->levels($this->activeMaterials->keys()->all());
    }

    /** Estimated cost of a form line at today's average cost; null while it can't be worked out. */
    public function lineCost(int $index): ?Money
    {
        $line = $this->form['lines'][$index] ?? null;
        $material = is_array($line) && ctype_digit((string) ($line['material_id'] ?? '')) ? $this->activeMaterials->get((int) $line['material_id']) : null;
        if ($material === null || trim((string) ($line['quantity'] ?? '')) === '') {
            return null;
        }

        try {
            $quantity = Quantity::fromInput((string) $line['quantity'], $material->unit->decimals);
        } catch (InvalidArgumentException) {
            return null;
        }

        $level = $this->levels[$material->id];

        return $quantity->isPositive() && ! $quantity->greaterThan($level['quantity']) ? app(MaterialStock::class)->valueOut($quantity, $level) : null;
    }

    public function create(): void
    {
        $this->authorize(Permission::MaterialsRecord->value);
        $this->resetErrorBag();
        $this->form = [
            'usage_date' => now()->format('Y-m-d'),
            'notes' => '',
            'lines' => [$this->blankLine()],
        ];
        $this->formKey = (string) Str::uuid();
        $this->showForm = true;
    }

    public function addLine(): void
    {
        if (count($this->form['lines'] ?? []) < RecordMaterialUsage::MAX_LINES) {
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

    public function save(RecordMaterialUsage $record): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $usage = $this->runAction(fn () => $record->handle($actor, $this->form, $this->formKey), 'form');

        $this->showForm = false;
        $this->toast(__(':number recorded.', ['number' => $usage->entry->number]));
        unset($this->usages, $this->totalCost, $this->levels);
    }

    protected function afterReverse(): void
    {
        unset($this->usages, $this->totalCost, $this->levels);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.materials.usage-index', [
            'canRecord' => $actor->can(Permission::MaterialsRecord->value),
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'range' => $this->dateRange(),
        ])->title(__('Materials used'));
    }

    /**
     * @return Builder<MaterialUsage>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        return MaterialUsage::query()
            ->whereBetween('usage_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when(ctype_digit($this->material), fn (Builder $q) => $q->whereHas('movements', fn (Builder $m) => $m->where('material_id', (int) $this->material)));
    }

    /**
     * @return array{key: string, material_id: string, quantity: string, memo: string}
     */
    private function blankLine(): array
    {
        return ['key' => (string) Str::uuid(), 'material_id' => '', 'quantity' => '', 'memo' => ''];
    }
}
