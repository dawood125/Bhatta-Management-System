<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaveExpenseCategory
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?ExpenseCategory $category, array $input): ExpenseCategory
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', $this->uniqueInKiln('expense_categories', 'name', $category?->id)],
            'name_ur' => ['nullable', 'string', 'max:100', $this->uniqueInKiln('expense_categories', 'name_ur', $category?->id)],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
        ])->validate();

        return DB::transaction(function () use ($category, $data): ExpenseCategory {
            $category ??= new ExpenseCategory(['is_active' => true]);
            $category->fill($data)->save();

            // The category's expense account (once it has one) carries the same name in reports.
            if ($category->ledger_account_id !== null) {
                LedgerAccount::query()->whereKey($category->ledger_account_id)->firstOrFail()
                    ->fill(['name' => $category->name, 'name_ur' => $category->name_ur])
                    ->save();
            }

            return $category;
        });
    }
}
