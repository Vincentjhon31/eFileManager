<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who has been deactivated mid-session.
 *
 * Checking only at sign-in would leave a deactivated employee working until
 * their session expired — up to an hour. For a system holding personnel and
 * financial records, revocation has to take effect on the next request.
 */
class EnsureUserIsActive
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! $user->canSignIn()) {
            $this->audit->log(
                event: 'user.session_revoked',
                subject: $user,
                description: 'Session ended because the account is deactivated.',
                actor: $user,
            );

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Contact your system administrator.']);
        }

        return $next($request);
    }
}
