<?php

namespace App\Actions\Production;

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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Pathai: raw bricks made by a team on a day (entry PT-…). Rejected bricks are recorded but
 * never enter stock; the accepted ones go into the raw stage.
 */
class RecordPathai
{
    use PostsProduction, ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  entry_date, team, made, rejected, notes
     */
    public function handle(User $actor, array $input, string $idempotencyKey): ProductionEntry
    {
        $this->authorizeSettings($actor, Permission::ProductionRecord);

        if (($existing = $this->existingEntry($idempotencyKey, ProductionEntryKind::Pathai)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['team', 'rejected', 'notes']);

        $data = Validator::make($input, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
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

        return $this->posting($idempotencyKey, ProductionEntryKind::Pathai, function () use ($actor, $data, $date, $made, $rejected, $accepted, $worker, $idempotencyKey): ProductionEntry {
            $entry = $this->createEntry(ProductionEntryKind::Pathai, $date, [
                'team' => $data['team'],
                'worker_id' => $worker?->id,
                'made' => $made,
                'rejected' => $rejected,
                'quantity' => $accepted,
                'notes' => $data['notes'],
            ], $idempotencyKey, $actor);

            if ($accepted->isPositive()) {
                $this->move($entry, null, StageKind::Raw, null, $accepted, $actor);
            }

            // Only accepted bricks are paid (rejected ones are not).
            $this->payWorker($entry, $worker, $accepted, $actor);

            return $entry;
        });
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
