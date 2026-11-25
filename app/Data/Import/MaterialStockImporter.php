<?php

namespace App\Data\Import;

use App\Actions\Materials\RecordMaterialAdjustment;
use App\Enums\Permission;
use App\Models\Material;
use App\Models\MaterialAdjustment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Soil, Kassu, coal and other materials already at the kiln, with what they cost. */
class MaterialStockImporter extends Importer
{
    public function key(): string
    {
        return 'material-stock';
    }

    public function title(): string
    {
        return __('Material opening stock');
    }

    public function description(): string
    {
        return __('Soil, Kassu, coal and other materials already at the kiln, with what they cost.');
    }

    public function columns(): array
    {
        return [
            'Material' => [true, __('See the Help sheet.')],
            'Quantity' => [true, __('In the unit of the material. Soil and Kassu: normal trolleys (one big trolley = 1.3).')],
            'Value' => [true, __('What this stock cost in total, in rupees.')],
            'As of date' => [false, __('Blank: today.')],
        ];
    }

    public function examples(): array
    {
        return [
            ['Soil (Matti)', '40', '120000', now()->format('d-m-Y')],
            ['Coal', '25', '1250000', now()->format('d-m-Y')],
        ];
    }

    public function allowedValues(): array
    {
        return [
            'Material' => Material::query()->with('unit')->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Material $m) => $m->name.' — '.($m->unit->symbol ?? $m->unit->displayName()))->all(),
        ];
    }

    public function allowed(User $actor): bool
    {
        return $actor->can(Permission::MaterialsAdjust->value);
    }

    /** @param  array<string, mixed>  $values */
    public function describe(array $values): string
    {
        return trim($this->value($values, 'Material').' '.$this->value($values, 'Quantity'));
    }

    public function importRow(User $actor, array $values, string $key): void
    {
        $material = $this->material($this->required($values, 'Material'));

        app(RecordMaterialAdjustment::class)->handle($actor, [
            'kind' => MaterialAdjustment::OPENING,
            'material_id' => (string) $material->id,
            'adjustment_date' => $this->date($values, 'As of date'),
            'quantity' => $this->number($values, 'Quantity'),
            'value' => $this->number($values, 'Value'),
            'reason' => '',
        ], $key);
    }

    private function material(string $text): Material
    {
        $plain = self::plain($text);
        $material = Material::query()->where('is_active', true)->get()->first(function (Material $m) use ($plain): bool {
            $names = [self::plain($m->name), self::plain((string) $m->name_ur), self::plain((string) $m->code)];
            // "Soil (Matti)" also matches "Soil" and "Matti".
            if (preg_match('/^(.*?)\s*\((.*)\)\s*$/u', $m->name, $parts)) {
                $names[] = self::plain($parts[1]);
                $names[] = self::plain($parts[2]);
            }

            return in_array($plain, $names, true);
        });

        if ($material === null) {
            throw ValidationException::withMessages(['Material' => __('Material ":material" is not known. See the Help sheet of the template.', ['material' => $text])]);
        }

        return $material;
    }
}
