<?php

namespace App\Livewire\Settings;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Your password, your other sessions, and what has been done with this account.
 *
 * The sign-in history is the point of this screen as much as the password form
 * is. Under RA 10173 an employee is entitled to know when their own account was
 * used and from where, and it is the cheapest way for somebody to notice a
 * sign-in that was not theirs. It reads the same audit trail an administrator
 * sees, scoped to this one account.
 */
class Security extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                // Eight is the floor the LGU asked for, with letters and
                // numbers required so the length is not spent on "12345678".
                // Not uncompromised(): that asks haveibeenpwned over the
                // network, and a municipal hall with an intermittent line
                // would find its password form failing for reasons nobody on
                // site could diagnose.
                Password::min(8)->letters()->numbers(),
                'different:current_password',
            ],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
        ];
    }

    public function updatePassword(AuditLogger $audit): void
    {
        $this->validate();

        $user = $this->user();
        $user->update(['password' => $this->password]);

        // Any other machine still holding this account open is signed out. A
        // password is usually changed because somebody fears it is known, and
        // leaving the old sessions alive would make the change ceremonial.
        $ended = $this->endOtherSessions($user);

        // The password itself is never written to the trail, here or anywhere.
        $audit->log(
            event: 'user.password_changed',
            subject: $user,
            properties: ['other_sessions_ended' => $ended],
            description: 'Changed their own password.',
        );

        $this->reset(['current_password', 'password', 'password_confirmation']);

        session()->flash('status', $ended > 0
            ? "Password changed. {$ended} other session(s) were signed out."
            : 'Password changed.');
    }

    public function signOutOtherSessions(AuditLogger $audit): void
    {
        $this->validate(['current_password' => ['required', 'current_password']]);

        $ended = $this->endOtherSessions($this->user());

        $audit->log(
            event: 'user.sessions_cleared',
            subject: $this->user(),
            properties: ['other_sessions_ended' => $ended],
            description: 'Signed out their other sessions.',
        );

        $this->reset('current_password');

        session()->flash('status', $ended > 0
            ? "{$ended} other session(s) signed out."
            : 'There were no other sessions to sign out.');
    }

    /**
     * Unlink Google sign-in.
     *
     * Only ever offered when a password exists to fall back on — which it
     * always does here, since accounts are created by an administrator with one
     * and Google is only ever linked to an account that already exists.
     */
    public function unlinkGoogle(AuditLogger $audit): void
    {
        $user = $this->user();

        if ($user->google_id === null) {
            return;
        }

        $user->update(['google_id' => null]);

        $audit->log(
            event: 'user.google_unlinked',
            subject: $user,
            description: 'Unlinked Google sign-in from their account.',
        );

        session()->flash('status', 'Google sign-in unlinked. Use your email and password from now on.');
    }

    /**
     * Drop every session row for this user except the one making the request.
     *
     * Works directly on the session table rather than through
     * Auth::logoutOtherDevices(), which depends on the AuthenticateSession
     * middleware being in the stack and re-hashes the password to do its work.
     * Deleting the rows is what actually ends the sessions under the database
     * driver, and it says plainly how many were ended.
     */
    private function endOtherSessions(User $user): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', session()->getId())
            ->delete();
    }

    private function user(): User
    {
        return Auth::user();
    }

    public function render()
    {
        $user = $this->user();

        return view('livewire.settings.security', [
            'user' => $user,

            // Both are needed before the link button means anything: credentials
            // on the server, and the municipality's switch left on.
            'googleConfigured' => filled(config('services.google.client_id'))
                && (bool) config('auth.google_enabled', true)
                && Route::has('auth.google.redirect'),

            // The account's own history, newest first. Sign-ins and the acts
            // that change how it is secured — not everything the person did,
            // which is the audit trail's job and a different screen.
            'activity' => AuditLog::query()
                ->where('user_id', $user->getKey())
                ->whereIn('event', [
                    'user.login', 'user.logout', 'user.password_changed',
                    'user.password_reset', 'user.sessions_cleared',
                    'user.google_linked', 'user.google_unlinked',
                    'user.session_revoked', 'user.profile_updated',
                ])
                ->latest('created_at')
                ->limit(10)
                ->get(),

            'otherSessions' => Schema::hasTable('sessions')
                ? DB::table('sessions')
                    ->where('user_id', $user->getKey())
                    ->where('id', '!=', session()->getId())
                    ->count()
                : 0,
        ])->layout('components.layouts.app', ['title' => 'Security']);
    }
}
