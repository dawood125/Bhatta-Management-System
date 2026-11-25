<?php

namespace App\Actions\Production;

use App\Actions\Labour\RecordWorkEntry;
use App\Actions\Materials\RecordMaterialUsage;
use App\Actions\Production\Concerns\PostsProduction;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Enums\ProductionEntryKind;
use App\Enums\StageKind;
use App\Models\Material;
use App\Models\MaterialUsage;
use App\Models\ProductionEntry;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Pathai: raw bricks made by a team on a day (entry PT-…). Rejected bricks are recorded but
 * never enter stock (their soil is mixed in again, so it is not used up); the accepted ones go
 * into the raw stage and take their soil, Kassu and any other raw material out of stock.
 *
 * Made by hand, the worker named earns the accepted bricks at his rate per 1,000. Made by the
 * machine, the worker named is its operator: he earns one day's wage, once per day, whatever
 * the bricks (client, 2026-11-25).
 */
class RecordPathai
{
    use PostsProduction, ValidatesSettings;

    public const BY_HAND = 'hand';

    public const BY_MACHINE = 'machine';

    public function __construct(
        private readonly RecordMaterialUsage $recordUsage,
        private readonly RecordWorkEntry $recordWork,
    ) {}

    /**
     * @param  array<string, mixed>  $input  entry_date, made_by (hand | machine, hand when left out), worker_id, team, made, rejected, notes
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionRecord);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Pathai)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['team', 'rejected', 'notes']);
        $input['made_by'] = ($input['made_by'] ?? '') !== '' ? $input['made_by'] : self::BY_HAND;

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'made_by' => ['required', Rule::in([self::BY_HAND, self::BY_MACHINE])],
            'team' => ['nullable', 'string', 'max:100'],
            'made' => ['required'],
            'rejected' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'made.required' => __('Enter how many bricks were made.'),
        ], [
            'entry_date' => __('date'),
            'team' => __('team'),
        ])->validate();

        $made = $this->bricks($data['made'], 'made');
        $rejected = $this->bricks($data['rejected'] ?? '', 'rejected', allowZero: true);

        if ($rejected->greaterThan($made)) {
            throw ValidationException::withMessages(['rejected' => __('More bricks rejected than made.')]);
        }

        $accepted = $made->subtract($rejected);
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();
        $worker = $this->workerFrom($input['worker_id'] ?? null);
        $byMachine = $data['made_by'] === self::BY_MACHINE;

        if ($byMachine && $worker === null) {
            throw ValidationException::withMessages(['worker_id' => __('Choose the machine operator.')]);
        }

        return $this->posting($idempotencyKey, ProductionEntryKind::Pathai, function () use ($actor, $data, $date, $made, $rejected, $accepted, $worker, $byMachine, $idempotencyKey): ProductionEntry {
            $usage = $this->useMaterials($actor, $date, $accepted, $data['notes'], $idempotencyKey);

            $entry = $this->createEntry(ProductionEntryKind::Pathai, $date, [
                'team' => $data['team'],
                'worker_id' => $worker?->id,
                'by_machine' => $byMachine,
                'made' => $made,
                'rejected' => $rejected,
                'quantity' => $accepted,
                'notes' => $data['notes'],
                'material_usage_id' => $usage?->id,
            ], $idempotencyKey, $actor);

            if ($accepted->isPositive()) {
                $this->move($entry, null, StageKind::Raw, null, $accepted, $actor);
            }

            if ($byMachine) {
                // The operator earns his day, not the bricks.
                $this->recordWork->dayForMachine($actor, $entry, $worker);
            } else {
                // Only accepted bricks are paid (rejected ones are not).
                $this->payWorker($entry, $worker, $accepted, $actor);
            }

            return $entry;
        });
    }

    /**
     * The active raw materials that go into every brick.
     *
     * @return Collection<int, Material>
     */
    public static function bricksMaterials(): Collection
    {
        return Material::query()->with('unit')
            ->where('is_active', true)
            ->where('kind', MaterialKind::RawMaterial)
            ->whereNotNull('bricks_per_unit')
            ->orderBy('sort_order')
            ->get();
    }

    /** Take the materials of these bricks out of stock; their errors show on the "made" field. */
    private function useMaterials(User $actor, Carbon $date, Quantity $accepted, ?string $notes, string $idempotencyKey): ?MaterialUsage
    {
        $lines = [];
        foreach (self::bricksMaterials() as $material) {
            $quantity = $material->usedFor($accepted);
            if ($quantity->isPositive()) {
                $lines[] = ['material_id' => $material->id, 'quantity' => $quantity->toDecimal(), 'memo' => null];
            }
        }

        if ($lines === []) {
            return null;
        }

        try {
            return $this->recordUsage->record($actor, [
                'usage_date' => $date->format('Y-m-d'),
                'notes' => $notes,
                'lines' => $lines,
            ], substr(trim($idempotencyKey), 0, 58).':mats', 3);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['made' => array_merge(...array_values($e->errors()))]);
        }
    }

    /** Accepted bricks for a made / rejected pair typed in a form; null while not valid yet. */
    public static function preview(string $made, string $rejected): ?Quantity
    {
        try {
            $m = Quantity::fromInput($made, 0);
            $r = trim($rejected) === '' ? Quantity::zero() : Quantity::fromInput($rejected, 0);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $m->isPositive() && ! $r->isNegative() && ! $r->greaterThan($m) ? $m->subtract($r) : null;
    }
}
