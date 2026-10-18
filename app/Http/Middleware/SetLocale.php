<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UI language: the user's saved preference, else the guest's session choice, else the default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $locale = $user !== null ? $user->locale : $request->session()->get('locale', config('app.locale'));

        if (! in_array($locale, User::LOCALES, true)) {
            $locale = config('app.locale');
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
