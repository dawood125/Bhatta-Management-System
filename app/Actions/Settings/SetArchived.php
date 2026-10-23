<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\Concerns\Archivable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Archive (hide from new entries) or restore a master-data record. Nothing is deleted.
 * Kiln settings by default; e.g. workers need the permission to manage workers instead.
 */
class SetArchived
{
    use ValidatesSettings;

    public function handle(User $actor, Model&Archivable $record, bool $archived, Permission $permission = Permission::SettingsManage): void
    {
        $this->authorizeSettings($actor, $permission);

        if ($archived && ($reason = $record->archiveBlocker()) !== null) {
            throw ValidationException::withMessages(['record' => $reason]);
        }

        $record->setAttribute('is_active', ! $archived);
        $record->save();
    }
}
