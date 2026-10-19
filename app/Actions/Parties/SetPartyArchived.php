<?php

namespace App\Actions\Parties;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hide a party that is no longer dealt with. Only when nothing is owed either way, so no
 * balance can disappear from view.
 */
class SetPartyArchived
{
    use ValidatesSettings;

    public function handle(User $actor, Party $party, bool $archived): void
    {
        $this->authorizeSettings($actor, Permission::PartiesManage);

        DB::transaction(function () use ($party, $archived): void {
            /** @var Party $locked */
            $locked = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();

            if ($archived && ! $locked->balance()->isZero()) {
                throw ValidationException::withMessages([
                    'party' => __('Only a party with a zero balance can be archived. Settle the balance first.'),
                ]);
            }

            $locked->is_active = ! $archived;
            $locked->save();
        });
    }
}
