<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Optional Google sign-in.
 *
 * Google is an authentication convenience only, never a source of identity.
 * An administrator creates the account first; Google can then be linked to it
 * and used to sign in. A Google identity that matches no existing account is
 * rejected — otherwise anyone with a Gmail address could obtain a foothold in
 * a government records system.
 *
 * These routes are only registered when credentials are configured.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless($this->enabled(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(AuditLogger $audit): RedirectResponse
    {
        abort_unless($this->enabled(), 404);

        // Signed in already means this is a linking attempt from Settings →
        // Security, not a sign-in. Different outcome, different failure
        // messages, and it must not be able to swap which account is signed in.
        if ($existing = Auth::user()) {
            return $this->link($existing, $audit);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Google sign-in could not be completed. Please try again.']);
        }

        // Match an already-linked account first, then fall back to email so an
        // employee can link Google on their first use without an admin step.
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if (! $user) {
            $audit->logAnonymous(
                event: 'user.google_login_rejected',
                properties: ['email' => $googleUser->getEmail()],
                description: 'Google sign-in for an address with no account in this system.',
            );

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'No account exists for that Google address. Contact the MIS Office.']);
        }

        if (! $user->canSignIn()) {
            $audit->logAnonymous(
                event: 'user.login_denied_inactive',
                properties: ['email' => $user->email],
                description: 'Google sign-in attempt on a deactivated account.',
            );

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Contact your system administrator.']);
        }

        $linked = blank($user->google_id);

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'last_login_at' => now(),
        ])->saveQuietly();

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        if ($linked) {
            $audit->log('user.google_linked', $user, description: 'Linked a Google account.', actor: $user);
        }

        $audit->log('user.login', $user, ['method' => 'google'], 'Signed in with Google.', $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Attach a Google identity to the account already signed in.
     *
     * Two refusals matter here. A Google identity already attached to another
     * account is rejected rather than moved, or one employee could quietly take
     * over another's sign-in; and the address is not required to match the
     * account's email, because a municipal Gmail and an official gov.ph address
     * are routinely different for the same person.
     */
    private function link(User $user, AuditLogger $audit): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()
                ->route('settings.security')
                ->withErrors(['google' => 'Google could not be reached. Nothing was linked — try again.']);
        }

        $takenBy = User::where('google_id', $googleUser->getId())
            ->whereKeyNot($user->getKey())
            ->first();

        if ($takenBy) {
            $audit->log(
                event: 'user.google_link_rejected',
                subject: $user,
                properties: ['reason' => 'already linked to another account'],
                description: 'Tried to link a Google account that belongs to somebody else.',
                actor: $user,
            );

            return redirect()
                ->route('settings.security')
                ->withErrors(['google' => 'That Google account is already linked to another account in this system.']);
        }

        $user->forceFill(['google_id' => $googleUser->getId()])->saveQuietly();

        $audit->log(
            event: 'user.google_linked',
            subject: $user,
            properties: ['google_email' => $googleUser->getEmail()],
            description: 'Linked a Google account from their settings.',
            actor: $user,
        );

        return redirect()
            ->route('settings.security')
            ->with('status', 'Google sign-in linked. You can now sign in either way.');
    }

    /**
     * Configured on the server, and switched on by the municipality.
     *
     * Both are needed: credentials without the setting means an administrator
     * has deliberately turned it off, and the setting without credentials would
     * be a button that leads nowhere.
     */
    private function enabled(): bool
    {
        return filled(config('services.google.client_id'))
            && (bool) config('auth.google_enabled', true);
    }
}
