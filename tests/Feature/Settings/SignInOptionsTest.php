<?php

namespace Tests\Feature\Settings;

use App\Enums\Role as RoleEnum;
use App\Livewire\Settings\Security;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How an employee may sign in, and who decides.
 *
 * Google is a convenience, never a source of identity: an administrator creates
 * the account, and Google can then be attached to it. Password sign-in has no
 * switch anywhere, on purpose — a system where it could be turned off is one
 * bad Google configuration away from locking every employee out of a government
 * records system with no way back in.
 */
class SignInOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->office = Department::factory()->onboarded()->create(['code' => 'MO']);
    }

    private function user(): User
    {
        $user = User::factory()->inDepartment($this->office)->create();
        $user->assignRole(RoleEnum::Staff->value);

        return $user;
    }

    /**
     * A server that has Google set up.
     *
     * The real routes are registered at boot only when credentials are present,
     * so setting the config alone is not enough to make the feature exist —
     * which is exactly the condition the screen checks, and why it checks it.
     */
    private function configureGoogle(bool $enabled = true): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'auth.google_enabled' => $enabled,
        ]);

        Route::get('/auth/google', fn () => null)->name('auth.google.redirect');
    }

    public function test_password_sign_in_is_always_offered_and_has_no_switch(): void
    {
        Livewire::actingAs($this->user())
            ->test(Security::class)
            ->assertSee('Email and password')
            ->assertSee('Always on');
    }

    public function test_google_is_shown_as_unavailable_when_it_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $screen = Livewire::actingAs($this->user())->test(Security::class);

        $this->assertFalse($screen->viewData('googleConfigured'));
        $screen->assertSee('Not set up on this server');
    }

    /**
     * An administrator turning it off must actually take the option away, not
     * merely hide the button.
     */
    public function test_switching_google_off_removes_the_option(): void
    {
        $this->configureGoogle(enabled: false);

        $screen = Livewire::actingAs($this->user())->test(Security::class);

        $this->assertFalse($screen->viewData('googleConfigured'));
    }

    public function test_an_employee_without_google_is_offered_the_link(): void
    {
        $this->configureGoogle();

        Livewire::actingAs($this->user())
            ->test(Security::class)
            ->assertSee('Link Google');
    }

    public function test_an_employee_with_google_linked_is_offered_the_unlink(): void
    {
        $this->configureGoogle();

        $user = $this->user();
        $user->update(['google_id' => 'google-abc']);

        Livewire::actingAs($user)
            ->test(Security::class)
            ->assertSee('Linked')
            ->assertSee('Unlink');
    }

    public function test_unlinking_leaves_the_password_working(): void
    {
        $user = $this->user();
        $user->update(['google_id' => 'google-abc']);

        Livewire::actingAs($user)
            ->test(Security::class)
            ->call('unlinkGoogle')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->google_id);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /*
    |--------------------------------------------------------------------------
    | Password rules
    |--------------------------------------------------------------------------
    */

    public function test_eight_characters_with_letters_and_numbers_is_accepted(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(Security::class)
            ->set('current_password', 'password')
            ->set('password', 'bongab01')
            ->set('password_confirmation', 'bongab01')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('bongab01', $user->fresh()->password));
    }

    public function test_seven_characters_is_still_too_short(): void
    {
        Livewire::actingAs($this->user())
            ->test(Security::class)
            ->set('current_password', 'password')
            ->set('password', 'bonga01')
            ->set('password_confirmation', 'bonga01')
            ->call('updatePassword')
            ->assertHasErrors('password');
    }

    /** Eight characters spent entirely on letters is not eight characters. */
    public function test_letters_and_numbers_are_both_required(): void
    {
        Livewire::actingAs($this->user())
            ->test(Security::class)
            ->set('current_password', 'password')
            ->set('password', 'bongabong')
            ->set('password_confirmation', 'bongabong')
            ->call('updatePassword')
            ->assertHasErrors('password');
    }
}
