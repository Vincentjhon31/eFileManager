<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_reachable(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_a_user_can_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_signing_in_records_the_time_and_an_audit_entry(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.login',
            'user_id' => $user->id,
        ]);
    }

    public function test_a_wrong_password_is_rejected_and_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.login_failed']);
    }

    /**
     * A deactivated employee must not get in even with the correct password,
     * and the failure must be indistinguishable from a wrong password so the
     * form cannot be used to discover who still has an account.
     */
    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');

        $this->assertSame(
            __('auth.failed'),
            session('errors')->first('email'),
            'A deactivated account must return the same message as a bad password.',
        );

        $this->assertDatabaseHas('audit_logs', ['event' => 'user.login_denied_inactive']);
    }

    public function test_sign_in_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();

        // The correct password on the sixth attempt must still be refused —
        // throttled, not merely rejected.
        $response->assertInvalid(['email' => 'seconds']);

        RateLimiter::clear(mb_strtolower($user->email).'|127.0.0.1');
    }

    public function test_a_user_can_sign_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.logout', 'user_id' => $user->id]);
    }

    /**
     * Deactivation must take effect on the next request, not at session expiry.
     * Otherwise a dismissed employee keeps working for up to an hour.
     */
    public function test_deactivating_a_user_ends_their_existing_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.session_revoked',
            'user_id' => $user->id,
        ]);
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->get('/admin/offices')->assertRedirect(route('login'));
        $this->get('/admin/audit')->assertRedirect(route('login'));
    }

    /** The public page needs no account at all — it is not behind 'guest' or 'auth'. */
    public function test_the_public_page_is_reachable_by_anyone(): void
    {
        $this->get('/')->assertOk();
        $this->actingAs(User::factory()->create())->get('/')->assertOk();
    }

    public function test_the_audit_trail_survives_the_user_being_deleted(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        Auth::logout();
        $user->delete();

        // The entry remains, with the actor name preserved on the row itself.
        $log = AuditLog::where('event', 'user.login')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
        $this->assertSame($user->name, $log->actor_name);
    }
}
