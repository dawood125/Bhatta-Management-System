<?php

namespace App\Actions\Labour;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Add or edit a worker (a person, a family or a team leader paid by the kiln).
 */
class SaveWorker
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  name, name_ur, phone, labour_category_id, joined_on, notes
     */
    public function handle(User $actor, ?Worker $worker, array $input): Worker
    {
        $this->authorizeSettings($actor, Permission::LabourWorkers);
        $input = $this->clean($input, ['name_ur', 'phone', 'joined_on', 'notes']);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'name_ur' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'labour_category_id' => ['required', 'integer', Rule::exists('labour_categories', 'id')->where('kiln_id', $this->kilnId())->where('is_active', true)],
            'joined_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'labour_category_id.exists' => __('Choose an active labour category.'),
        ], [
            'labour_category_id' => __('category'),
            'joined_on' => __('joining date'),
        ])->validate();

        return DB::transaction(function () use ($worker, $data): Worker {
            $worker ??= new Worker(['is_active' => true]);
            $worker->fill($data)->save();

            return $worker;
        });
    }
}
