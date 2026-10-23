<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /settings opens the first settings page this user may use.
 */
class SettingsHomeController
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->can(Permission::KilnProfileManage->value)) {
            return redirect()->route('settings.kiln');
        }

        abort_unless($user->can(Permission::SettingsManage->value), 403);

        return redirect()->route('settings.products');
    }
}
