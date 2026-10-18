<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switch the UI language. Saved on the user (so it follows them to any device) or,
 * for guests on the login page, in the session.
 */
class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(User::LOCALES)],
        ]);

        /** @var User|null $user */
        $user = $request->user();
        $user?->forceFill(['locale' => $data['locale']])->saveQuietly();
        $request->session()->put('locale', $data['locale']);

        return redirect()->back();
    }
}
