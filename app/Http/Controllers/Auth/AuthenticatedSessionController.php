<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Sign in and out.
 *
 * Plain controller rather than a Livewire component: authentication needs
 * session regeneration and rate limiting on a full page request, and this is
 * the path every Laravel developer already recognises. Livewire is used for
 * the interactive screens, where it earns its complexity.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Prevents session fixation: the pre-login session id is discarded.
        $request->session()->regenerate();

        return redirect()->intended($this->landingUrl($request->user()));
    }

    /**
     * Where signing in puts you.
     *
     * The employee's own choice from Settings → Preferences, but checked
     * against what they may actually reach: a receiving clerk who picked My
     * Desk and later moved to an office that does not use the system would
     * otherwise be met by a 403 on every sign-in. The dashboard is reachable by
     * anybody with an account, so it is always a safe answer.
     *
     * intended() still wins — somebody who followed a link to a document and
     * was asked to sign in should land on that document, not on a preference.
     */
    private function landingUrl(?User $user): string
    {
        $route = $user?->preferences()->landing() ?? 'dashboard';

        if ($route === 'dashboard' || ! Route::has($route)) {
            return route('dashboard');
        }

        // Ask the route's own middleware whether this user would be let in,
        // rather than restating each screen's permission here — two copies of
        // that rule would drift, and the drift would be a 403 on sign-in.
        foreach (Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                if ($user === null || ! $user->can(Str::after($middleware, 'can:'))) {
                    return route('dashboard');
                }
            }
        }

        return route($route);
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        if ($user = Auth::user()) {
            $audit->log(
                event: 'user.logout',
                subject: $user,
                description: 'Signed out.',
                actor: $user,
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
