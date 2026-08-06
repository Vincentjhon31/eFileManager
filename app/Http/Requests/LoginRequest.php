<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Attempt to sign in.
     *
     * Deactivated accounts are rejected with the same generic message as bad
     * credentials, and only after the attempt is throttled, so the form cannot
     * be used to enumerate which employees still have active accounts.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $audit = app(AuditLogger::class);

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            $audit->logAnonymous(
                event: 'user.login_failed',
                properties: ['email' => $this->string('email')->value()],
                description: 'Sign-in attempt with invalid credentials.',
            );

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->canSignIn()) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            $audit->logAnonymous(
                event: 'user.login_denied_inactive',
                properties: ['email' => $user->email],
                description: 'Sign-in attempt on a deactivated account.',
            );

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $audit->log(
            event: 'user.login',
            subject: $user,
            description: 'Signed in.',
            actor: $user,
        );
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /** Throttled per email and IP together, so one attacker cannot lock out a colleague. */
    protected function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')->value()).'|'.$this->ip()
        );
    }
}
