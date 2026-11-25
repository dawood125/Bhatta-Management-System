<?php

namespace App\Data\Import;

use App\Actions\Production\RecordBrickAdjustment;
use App\Enums\Permission;
use App\Enums\ProductKind;
use App\Enums\StageKind;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Bricks already at each stage on the first day (Production → Adjust stock → Opening stock). */
class BrickStockImporter extends Importer
{
    public function key(): string
    {
        return 'brick-stock';
    }

    public function title(): string
    {
        return __('Brick opening stock');
    }

    public function description(): string
    {
        return __('Bricks already drying, in the kiln, fired, waiting to re-fire, or ready for sale by grade.');
    }

    public function columns(): array
    {
        return [
            'Stage' => [true, __('See the Help sheet.')],
            'Grade' => [false, __('Only for "Ready for sale", e.g. Awal.')],
            'Quantity' => [true, __('Number of bricks.')],
            'As of date' => [false, __('Blank: today.')],
        ];
    }

    public function examples(): array
    {
        return [
            ['Raw bricks (Pathai)', '', '150000', now()->format('d-m-Y')],
            ['In the kiln (Julai)', '', '300000', now()->format('d-m-Y')],
            ['Ready for sale', 'Awal', '80000', now()->format('d-m-Y')],
        ];
    }

    public function allowedValues(): array
    {
        $stages = ProductionStage::query()->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);

        return [
            'Stage' => array_map(fn (StageKind $k) => $stages->get($k->value)->name ?? $k->defaultName(), StageKind::cases()),
            'Grade' => $this->grades()->map(fn (Product $p) => $p->name)->values()->all(),
        ];
    }

    public function allowed(User $actor): bool
    {
        return $actor->can(Permission::ProductionAdjust->value);
    }

    /** @param  array<string, mixed>  $values */
    public function describe(array $values): string
    {
        return trim($this->value($values, 'Stage').' '.$this->value($values, 'Grade').' '.$this->value($values, 'Quantity'));
    }

    public function importRow(User $actor, array $values, string $key): void
    {
        $stage = $this->stage($this->required($values, 'Stage'));
        $grade = $this->value($values, 'Grade');
        $product = null;

        if ($stage === StageKind::Saleable) {
            if ($grade === '') {
                throw ValidationException::withMessages(['Grade' => __('Ready-for-sale bricks need a grade.')]);
            }
            $plain = self::plain($grade);
            $product = $this->grades()->first(fn (Product $p) => in_array($plain, [self::plain($p->name), self::plain((string) $p->name_ur), self::plain((string) $p->code)], true));
            if ($product === null) {
                throw ValidationException::withMessages(['Grade' => __('Grade ":grade" is not known. See the Help sheet of the template.', ['grade' => $grade])]);
            }
        }

        app(RecordBrickAdjustment::class)->handle($actor, [
            'entry_date' => $this->date($values, 'As of date'),
            'stage' => $stage->value,
            'product_id' => $product !== null ? (string) $product->id : '',
            'direction' => RecordBrickAdjustment::OPENING,
            'quantity' => $this->number($values, 'Quantity'),
            'reason' => '',
        ], $key);
    }

    private function stage(string $text): StageKind
    {
        $plain = self::plain($text);
        $stages = ProductionStage::query()->get()->keyBy(fn (ProductionStage $s) => $s->kind->value);

        foreach (StageKind::cases() as $kind) {
            $names = [$kind->value, $kind->defaultName(), $kind->defaultNameUrdu(), $stages->get($kind->value)?->name, $stages->get($kind->value)?->name_ur];
            // Short names people use: "Raw", "Pathai", "Kiln", "Julai", "Fired", "Re-fire", "Ready".
            $names = array_merge($names, match ($kind) {
                StageKind::Raw => ['raw', 'pathai', 'kachi'],
                StageKind::InFiring => ['kiln', 'julai', 'infiring', 'inthekiln'],
                StageKind::FiredAwaitingUnloading => ['fired', 'nakaasi'],
                StageKind::AwaitingRefire => ['refire', 'waitingtorefire'],
                StageKind::Saleable => ['ready', 'readyforsale', 'saleable', 'pakki'],
            });

            if (in_array($plain, array_map(fn ($n) => self::plain((string) $n), $names), true)) {
                return $kind;
            }
        }

        throw ValidationException::withMessages(['Stage' => __('Stage ":stage" is not known. See the Help sheet of the template.', ['stage' => $text])]);
    }

    /** @return Collection<int, Product> */
    private function grades(): Collection
    {
        return Product::query()->where('is_active', true)->whereIn('kind', [ProductKind::FiredGrade, ProductKind::ByProduct])->orderBy('sort_order')->get();
    }
}
