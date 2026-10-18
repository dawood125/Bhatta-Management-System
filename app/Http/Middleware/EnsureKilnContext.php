<?php

namespace App\Http\Middleware;

use App\Models\Kiln;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every authenticated request: make sure the account is still active and has access to an
 * active kiln, then set that kiln as the current context (data scope + permission team).
 */
class EnsureKilnContext
{
    public function __construct(private readonly CurrentKiln $currentKiln) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->logout($request, __('This account has been deactivated. Please contact the kiln owner.'));
        }

        $kiln = $this->resolveKiln($user, $request->session()->get('kiln_id'));

        if ($kiln === null) {
            return $this->logout($request, __('This account has no access to any kiln.'));
        }

        $request->session()->put('kiln_id', $kiln->id);
        $this->currentKiln->set($kiln);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }

    private function resolveKiln(User $user, mixed $sessionKilnId): ?Kiln
    {
        $kilns = $user->kilns()->where('kilns.is_active', true);

        if (is_numeric($sessionKilnId)) {
            $kiln = (clone $kilns)->whereKey((int) $sessionKilnId)->first();
            if ($kiln !== null) {
                return $kiln;
            }
        }

        return $kilns->orderBy('kilns.id')->first();
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $errors = (new ViewErrorBag)->put('default', new MessageBag(['login' => [$message]]));

        if ($request->hasHeader('X-Livewire')) {
            // The browser script sends the user to the login page on 401; the message waits there.
            $request->session()->flash('errors', $errors);
            abort(401, $message);
        }

        return redirect()->route('login')->with('errors', $errors);
    }
}
