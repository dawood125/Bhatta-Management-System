<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveExpenseSubCategory
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?ExpenseSubCategory $subCategory, array $input): ExpenseSubCategory
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);

        $kilnId = $this->kilnId();
        $categoryId = (int) ($input['expense_category_id'] ?? 0);

        $data = Validator::make($input, [
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('kiln_id', $kilnId)],
            // Names are unique inside their category (two categories may both have "Other").
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_sub_categories', 'name')->where('expense_category_id', $categoryId)->ignore($subCategory?->id)],
            'name_ur' => ['nullable', 'string', 'max:100', Rule::unique('expense_sub_categories', 'name_ur')->where('expense_category_id', $categoryId)->ignore($subCategory?->id)],
        ])->validate();

        return DB::transaction(function () use ($subCategory, $data): ExpenseSubCategory {
            ExpenseCategory::query()->findOrFail((int) $data['expense_category_id']);

            $subCategory ??= new ExpenseSubCategory(['is_active' => true]);
            $subCategory->fill($data)->save();

            return $subCategory;
        });
    }
}
