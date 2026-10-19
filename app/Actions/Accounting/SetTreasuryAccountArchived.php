<?php

namespace App\Actions\Accounting;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\TreasuryAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hide an account that is no longer used. Only possible when its balance is exactly zero, so no
 * money can disappear from view.
 */
class SetTreasuryAccountArchived
{
    use ValidatesSettings;

    public function handle(User $actor, TreasuryAccount $account, bool $archived): void
    {
        $this->authorizeSettings($actor, Permission::AccountsManage);

        DB::transaction(function () use ($account, $archived): void {
            /** @var TreasuryAccount $locked */
            $locked = TreasuryAccount::query()->whereKey($account->id)->lockForUpdate()->with('ledgerAccount')->firstOrFail();

            if ($archived && ! $locked->balance()->isZero()) {
                throw ValidationException::withMessages([
                    'account' => __('Only an account with a zero balance can be archived. Move the remaining money first.'),
                ]);
            }

            $locked->is_active = ! $archived;
            $locked->save();
        });
    }
}
