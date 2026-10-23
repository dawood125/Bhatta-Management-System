<?php

namespace App\Actions\Labour;

use App\Accounting\ChartOfAccounts;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\LabourActivity;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Models\LabourCategory;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit a labour category. Its pay basis is fixed once wages have been earned in it,
 * because every rate and entry in its history is counted that way.
 */
class SaveLabourCategory
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  name, name_ur, basis, activity, sort_order
     */
    public function handle(User $actor, ?LabourCategory $category, array $input): LabourCategory
    {
        $this->authorizeSettings($actor, Permission::LabourRates);
        $input = $this->clean($input, ['name_ur', 'activity']);

        // Only piece-rate work is paid from production entries.
        if (($input['basis'] ?? null) !== PayBasis::PerThousand->value) {
            $input['activity'] = null;
        }

        $data = Validator::make($input, [
            // 90, not 100: the wages account adds " wages" to the name and has room for 100.
            'name' => ['required', 'string', 'max:90', $this->uniqueInKiln('labour_categories', 'name', $category?->id)],
            'name_ur' => ['nullable', 'string', 'max:90', $this->uniqueInKiln('labour_categories', 'name_ur', $category?->id)],
            'basis' => ['required', Rule::enum(PayBasis::class)],
            'activity' => ['nullable', Rule::enum(LabourActivity::class)],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
        ])->validate();

        if ($category !== null && $data['basis'] !== $category->basis->value && WorkEntry::query()->where('labour_category_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['basis' => __('Wages have been earned in this category, so how it is paid can no longer change.')]);
        }

        return DB::transaction(function () use ($category, $data): LabourCategory {
            $category ??= new LabourCategory(['is_active' => true]);
            $category->fill($data)->save();

            // Its wages account (once it has one) carries the same name in reports.
            if ($category->ledger_account_id !== null) {
                [$name, $nameUr] = ChartOfAccounts::labourAccountNames($category);
                LedgerAccount::query()->whereKey($category->ledger_account_id)->firstOrFail()->fill(['name' => $name, 'name_ur' => $nameUr])->save();
            }

            return $category;
        });
    }
}
