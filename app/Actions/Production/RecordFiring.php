<?php

namespace App\Actions\Production;

use App\Actions\Materials\RecordMaterialUsage;
use App\Actions\Production\Concerns\PostsProduction;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Firing (entry FR-…): bricks whose firing is complete move to "fired, awaiting Nakaasi", and
 * the fuel burnt (coal, Bora, ...) is taken out of material stock at its average cost. Either
 * part may be empty, but not both.
 */
class RecordFiring
{
    use PostsProduction, ValidatesSettings;

    public const MAX_FUEL_LINES = 10;

    public function __construct(private readonly RecordMaterialUsage $recordUsage) {}

    /**
     * @param  array<string, mixed>  $input  entry_date, quantity, notes, fuel[] = {material_id, quantity}
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionRecord);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Firing)) !== null) {
            return $existing;
        }

        $fuel = is_array($input['fuel'] ?? null)
            ? array_values(array_map(fn ($line) => is_array($line) ? $this->clean($line, []) : $line, $input['fuel']))
            : [];
        $input = $this->clean(array_diff_key($input, ['fuel' => true]), ['quantity', 'notes']) + ['fuel' => $fuel];

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'quantity' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:255'],
            'fuel' => ['array', 'max:'.self::MAX_FUEL_LINES],
            'fuel.*' => ['required', 'array'],
            'fuel.*.material_id' => ['required', 'integer', 'distinct', Rule::exists('materials', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)],
            'fuel.*.quantity' => ['required'],
        ], [
            'fuel.*.material_id.distinct' => __('Each material only once per entry.'),
            'fuel.*.material_id.exists' => __('This material is archived or does not exist.'),
            'fuel.*.quantity.required' => __('Enter the quantity.'),
        ], [
            'entry_date' => __('date'),
            'fuel.*.material_id' => __('material'),
        ])->validate();

        $quantity = $this->bricks($data['quantity'] ?? '', 'quantity', allowZero: true);
        $fuelLines = array_values($data['fuel']);

        if ($quantity->isZero() && $fuelLines === []) {
            throw ValidationException::withMessages(['quantity' => __('Enter the bricks fired, the fuel used, or both.')]);
        }

        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();

        return $this->posting($idempotencyKey, ProductionEntryKind::Firing, function () use ($actor, $data, $date, $quantity, $fuelLines, $idempotencyKey): ProductionEntry {
            if ($quantity->isPositive()) {
                $this->assertStageAllows(StageKind::InFiring, null, $date, Quantity::zero()->subtract($quantity), 'quantity');
            }

            // Fuel first: its errors name the fuel lines ("fuel.0.quantity").
            $usage = null;
            if ($fuelLines !== []) {
                try {
                    $usage = $this->recordUsage->record($actor, [
                        'usage_date' => $date->format('Y-m-d'),
                        'notes' => $data['notes'],
                        'lines' => array_map(fn (array $line) => ['material_id' => $line['material_id'], 'quantity' => $line['quantity'], 'memo' => null], $fuelLines),
                    ], substr(trim($idempotencyKey), 0, 58).':fuel');
                } catch (ValidationException $e) {
                    $messages = [];
                    foreach ($e->errors() as $key => $errors) {
                        $messages[str_starts_with($key, 'lines') ? 'fuel'.substr($key, 5) : $key] = $errors;
                    }

                    throw ValidationException::withMessages($messages);
                }
            }

            $entry = $this->createEntry(ProductionEntryKind::Firing, $date, [
                'quantity' => $quantity,
                'notes' => $data['notes'],
                'material_usage_id' => $usage?->id,
            ], $idempotencyKey, $actor);

            if ($quantity->isPositive()) {
                $this->move($entry, StageKind::InFiring, StageKind::FiredAwaitingUnloading, null, $quantity, $actor);
            }

            return $entry;
        });
    }
}
