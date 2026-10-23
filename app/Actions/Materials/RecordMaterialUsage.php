<?php

namespace App\Actions\Materials;

use App\Accounting\ChartOfAccounts;
use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Materials\Concerns\GuardsStock;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\MaterialMovementType;
use App\Enums\Permission;
use App\Materials\MaterialStock;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MaterialMovement;
use App\Models\MaterialUsage;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Materials taken out of stock and used, e.g. the day's coal and Bora (entry MU-…). Each material
 * is valued at its average cost and that cost moves to the material's own "... used" expense
 * account. Stock can never go below zero, on the date or any later day.
 */
class RecordMaterialUsage
{
    use GuardsStock, ValidatesSettings;

    public const MAX_LINES = 20;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ChartOfAccounts $chartOfAccounts,
        private readonly MaterialStock $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  usage_date, notes, lines[] = {material_id, quantity, memo}
     */
    public function handle(User $actor, array $input, string $idempotencyKey): MaterialUsage
    {
        $this->authorizeSettings($actor, Permission::MaterialsRecord);

        if (($existing = $this->findByKey($idempotencyKey)) !== null) {
            return $existing;
        }

        $lines = is_array($input['lines'] ?? null)
            ? array_values(array_map(fn ($line) => is_array($line) ? $this->clean($line, ['memo']) : $line, $input['lines']))
            : ($input['lines'] ?? null);
        $input = $this->clean(array_diff_key($input, ['lines' => true]), ['notes']) + ['lines' => $lines];
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'usage_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.material_id' => ['required', 'integer', 'distinct', Rule::exists('materials', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'lines.*.quantity' => ['required'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.required' => __('Add at least one material.'),
            'lines.min' => __('Add at least one material.'),
            'lines.max' => __('At most :max materials at a time.'),
            'lines.*.material_id.distinct' => __('Each material only once per entry.'),
            'lines.*.material_id.exists' => __('This material is archived or does not exist.'),
            'lines.*.quantity.required' => __('Enter the quantity.'),
        ], [
            'usage_date' => __('date'),
            'lines.*.material_id' => __('material'),
            'lines.*.memo' => __('remarks'),
        ])->validate();

        $date = Carbon::createFromFormat('Y-m-d', $data['usage_date'])->startOfDay();
        $rows = array_values($data['lines']);

        return DB::transaction(function () use ($actor, $data, $date, $rows, $idempotencyKey): MaterialUsage {
            $materials = $this->lockMaterials(array_map(fn ($row) => (int) $row['material_id'], $rows));
            $levels = $this->stock->levels($materials->keys()->all());

            $lines = [];
            $errors = [];
            foreach ($rows as $index => $row) {
                $material = $materials[(int) $row['material_id']];

                try {
                    $quantity = Quantity::fromInput((string) $row['quantity'], $material->unit->decimals);
                } catch (InvalidArgumentException) {
                    $errors["lines.{$index}.quantity"] = $material->unit->decimals === 0
                        ? __('Enter a whole number.')
                        : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $material->unit->decimals]);

                    continue;
                }

                if (! $quantity->isPositive()) {
                    $errors["lines.{$index}.quantity"] = __('The quantity must be more than zero.');

                    continue;
                }

                try {
                    $this->assertStockAllows($material, $date, Quantity::zero()->subtract($quantity), "lines.{$index}.quantity");
                } catch (ValidationException $e) {
                    $errors += array_map(fn (array $messages) => $messages[0], $e->errors());

                    continue;
                }

                $lines[] = ['material' => $material, 'quantity' => $quantity, 'value' => $this->stock->valueOut($quantity, $levels[$material->id]), 'memo' => $row['memo'] ?? null];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $total = Money::sum(array_column($lines, 'value'));
            if (! $total->isPositive()) {
                throw ValidationException::withMessages(['lines' => __('These quantities are worth less than one paisa. Enter a larger quantity.')]);
            }

            $pending = [];
            foreach ($lines as $line) {
                if ($line['value']->isPositive()) {
                    $pending[] = PendingLine::debit($this->chartOfAccounts->usageAccountFor($line['material']), $line['value'], $line['memo']);
                }
            }
            $pending[] = PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::MATERIAL_STOCK), $total);

            $entry = $this->poster->post(JournalType::MaterialUsage, $date, $pending, $idempotencyKey, $actor, $data['notes']);

            if (! $entry->wasRecentlyCreated) {
                return MaterialUsage::query()->where('journal_entry_id', $entry->id)->firstOrFail();
            }

            $usage = MaterialUsage::query()->create([
                'journal_entry_id' => $entry->id,
                'usage_date' => $date->format('Y-m-d'),
                'notes' => $data['notes'],
                'total_cost' => $total,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                MaterialMovement::query()->create([
                    'material_id' => $line['material']->id,
                    'movement_date' => $date->format('Y-m-d'),
                    'type' => MaterialMovementType::Usage,
                    'quantity_in' => Quantity::zero(),
                    'quantity_out' => $line['quantity'],
                    'value_in' => Money::zero(),
                    'value_out' => $line['value'],
                    'journal_entry_id' => $entry->id,
                    'material_usage_id' => $usage->id,
                    'memo' => $line['memo'],
                    'created_by' => $actor->id,
                ]);
            }

            return $usage;
        });
    }

    private function findByKey(string $idempotencyKey): ?MaterialUsage
    {
        $entry = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->type !== JournalType::MaterialUsage) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return MaterialUsage::query()->where('journal_entry_id', $entry->id)->firstOrFail();
    }
}
