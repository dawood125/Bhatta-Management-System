<?php

namespace App\Actions\Labour;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\LabourCategory;
use App\Models\LabourRate;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rates with effective dates: a new rate starts on a date and earlier work keeps its old rate.
 * A category rate applies to all its workers; a worker's own rate (e.g. a salary) overrides it.
 * A rate can be removed only while no wages were worked out with it.
 */
class SaveLabourRate
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  labour_category_id, worker_id (optional), effective_from, rate
     */
    public function handle(User $actor, array $input): LabourRate
    {
        $this->authorizeSettings($actor, Permission::LabourRates);
        $input = $this->clean($input, ['worker_id']);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'labour_category_id' => ['required', 'integer', Rule::exists('labour_categories', 'id')->where('kiln_id', $kilnId)],
            'worker_id' => ['nullable', 'integer', Rule::exists('workers', 'id')->where('kiln_id', $kilnId)
                ->where('labour_category_id', (int) ($input['labour_category_id'] ?? 0))],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'rate' => ['required', new MoneyAmount],
        ], [
            'worker_id.exists' => __('Choose a worker of this category.'),
        ], [
            'effective_from' => __('starting date'),
            'rate' => __('rate'),
        ])->validate();

        $category = LabourCategory::query()->findOrFail((int) $data['labour_category_id']);
        if (! $category->basis->hasRate()) {
            throw ValidationException::withMessages(['rate' => __('Contracts are paid in agreed amounts; they have no rate.')]);
        }

        $workerId = $data['worker_id'] !== null ? (int) $data['worker_id'] : null;

        return DB::transaction(function () use ($actor, $category, $workerId, $data): LabourRate {
            $exists = LabourRate::query()
                ->where('labour_category_id', $category->id)
                ->where(fn ($q) => $workerId !== null ? $q->where('worker_id', $workerId) : $q->whereNull('worker_id'))
                ->where('effective_from', $data['effective_from'])
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['effective_from' => __('A rate already starts on this date. Remove it first, or choose another date.')]);
            }

            $rate = LabourRate::query()->create([
                'labour_category_id' => $category->id,
                'worker_id' => $workerId,
                'effective_from' => $data['effective_from'],
                'rate' => Money::fromRupees((string) $data['rate']),
                'created_by' => $actor->id,
            ]);

            activity()->performedOn($workerId !== null ? Worker::query()->findOrFail($workerId) : $category)->causedBy($actor)
                ->event('rate_set')
                ->withProperties(['attributes' => ['rate' => $rate->rate->format(), 'effective_from' => $data['effective_from']]])
                ->log('rate_set');

            return $rate;
        });
    }

    public function remove(User $actor, LabourRate $rate): void
    {
        $this->authorizeSettings($actor, Permission::LabourRates);

        if (WorkEntry::query()->where('labour_rate_id', $rate->id)->exists()) {
            throw ValidationException::withMessages(['rate' => __('Wages were worked out with this rate, so it stays. Add a new rate from a later date instead.')]);
        }

        DB::transaction(function () use ($actor, $rate): void {
            activity()->performedOn($rate->worker ?? $rate->category)->causedBy($actor)
                ->event('rate_removed')
                ->withProperties(['old' => ['rate' => $rate->rate->format(), 'effective_from' => $rate->effective_from->format('Y-m-d')]])
                ->log('rate_removed');

            $rate->delete();
        });
    }
}
