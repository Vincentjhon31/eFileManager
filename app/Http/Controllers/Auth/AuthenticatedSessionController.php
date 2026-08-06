<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return redirect()->intended(route('dashboard'));
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
