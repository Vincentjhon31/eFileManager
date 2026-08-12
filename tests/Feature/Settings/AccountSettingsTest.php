<?php

namespace Tests\Feature\Settings;

use App\Enums\Role as RoleEnum;
use App\Livewire\Settings\Notifications as NotificationSettings;
use App\Livewire\Settings\Preferences as PreferenceSettings;
use App\Livewire\Settings\Profile as ProfileSettings;
use App\Livewire\Settings\Security as SecuritySettings;
use App\Models\Department;
use App\Models\User;
use App\Support\UserPreferences;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The settings that belong to one employee.
 *
 * The theme running through these: a preference decides what a screen looks
 * like to the person who set it, and never what they are allowed to reach. The
 * fields an administrator owns — office, role, email — stay out of reach here
 * however the form is posted.
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->office = Department::factory()->onboarded()->create(['code' => 'MO']);
        $this->employee = $this->user();
    }

    private function user(RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($this->office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    public function test_an_employee_edits_their_own_name_position_and_number(): void
    {
        Livewire::actingAs($this->employee)
            ->test(ProfileSettings::class)
            ->set('name', 'Juana dela Cruz')
            ->set('position', 'Records Officer III')
            ->set('phone', '0917 555 0100')
            ->call('save')
            ->assertHasNoErrors();

        $this->employee->refresh();

        $this->assertSame('Juana dela Cruz', $this->employee->name);
        $this->assertSame('Records Officer III', $this->employee->position);
        $this->assertSame('0917 555 0100', $this->employee->phone);

        // Their name appears on routing slips and in the document trail, so a
        // change to it has to be reconcilable afterwards.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.profile_updated',
            'user_id' => $this->employee->id,
        ]);
    }

    public function test_a_profile_needs_a_name(): void
    {
        Livewire::actingAs($this->employee)
            ->test(ProfileSettings::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    }

    /**
     * The heart of it: office and role decide what this account may reach, and
     * an account that could move itself between offices would undo the whole
     * visibility model. There is no property here to set, and posting one does
     * not create one.
     */
    public function test_the_profile_screen_cannot_move_an_account_between_offices(): void
    {
        $otherOffice = Department::factory()->onboarded()->create(['code' => 'LEGAL']);
        $originalEmail = $this->employee->email;

        Livewire::actingAs($this->employee)
            ->test(ProfileSettings::class)
            ->set('name', 'Still Me')
            ->call('save')
            ->assertHasNoErrors();

        $this->employee->refresh();

        $this->assertSame($this->office->id, $this->employee->department_id);
        $this->assertNotSame($otherOffice->id, $this->employee->department_id);
        $this->assertSame($originalEmail, $this->employee->email);
    }

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    public function test_a_password_is_changed_with_the_current_one(): void
    {
        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'password')
            ->set('password', 'a-longer-secret-2026')
            ->set('password_confirmation', 'a-longer-secret-2026')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('a-longer-secret-2026', $this->employee->fresh()->password));

        // The password itself is never written to the trail.
        $entry = DB::table('audit_logs')->where('event', 'user.password_changed')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('a-longer-secret-2026', json_encode($entry));
    }

    public function test_the_wrong_current_password_changes_nothing(): void
    {
        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'not-my-password')
            ->set('password', 'a-longer-secret-2026')
            ->set('password_confirmation', 'a-longer-secret-2026')
            ->call('updatePassword')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->employee->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'password')
            ->set('password', 'short1')
            ->set('password_confirmation', 'short1')
            ->call('updatePassword')
            ->assertHasErrors('password');
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'password')
            ->set('password', 'a-longer-secret-2026')
            ->set('password_confirmation', 'something-else-2026')
            ->call('updatePassword')
            ->assertHasErrors('password');
    }

    /**
     * A password is usually changed because somebody fears it is known, so
     * leaving the old sessions alive would make the change ceremonial.
     */
    public function test_changing_a_password_signs_out_the_other_machines(): void
    {
        $this->seedSession($this->employee, 'a-session-from-the-counter-pc');
        $this->seedSession($this->employee, 'a-session-from-a-phone');
        $this->seedSession($this->user(), 'somebody-elses-session');

        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'password')
            ->set('password', 'a-longer-secret-2026')
            ->set('password_confirmation', 'a-longer-secret-2026')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->employee->id)->count());

        // And nobody else's.
        $this->assertSame(1, DB::table('sessions')->where('id', 'somebody-elses-session')->count());
    }

    public function test_other_sessions_can_be_ended_without_changing_the_password(): void
    {
        $this->seedSession($this->employee, 'a-forgotten-session');

        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'password')
            ->call('signOutOtherSessions')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->employee->id)->count());
        $this->assertTrue(Hash::check('password', $this->employee->fresh()->password));
    }

    public function test_ending_other_sessions_needs_the_password(): void
    {
        $this->seedSession($this->employee, 'a-forgotten-session');

        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->set('current_password', 'wrong')
            ->call('signOutOtherSessions')
            ->assertHasErrors('current_password');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->employee->id)->count());
    }

    public function test_google_sign_in_can_be_unlinked(): void
    {
        $this->employee->update(['google_id' => 'google-12345']);

        Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->call('unlinkGoogle')
            ->assertHasNoErrors();

        $this->assertNull($this->employee->fresh()->google_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.google_unlinked']);
    }

    /** The account's own history, and nobody else's. */
    public function test_the_security_screen_shows_only_this_accounts_activity(): void
    {
        $other = $this->user();

        $this->post(route('login.store'), ['email' => $this->employee->email, 'password' => 'password']);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password']);

        $activity = Livewire::actingAs($this->employee)
            ->test(SecuritySettings::class)
            ->viewData('activity');

        $this->assertGreaterThan(0, $activity->count());
        $this->assertTrue($activity->every(fn ($entry) => $entry->user_id === $this->employee->id));
    }

    private function seedSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Preferences
    |--------------------------------------------------------------------------
    */

    public function test_preferences_are_saved(): void
    {
        Livewire::actingAs($this->employee)
            ->test(PreferenceSettings::class)
            ->set('landing', 'drive')
            ->set('rows_per_page', 50)
            ->set('date_format', 'Y-m-d')
            ->set('time_format', 'H:i')
            ->set('drive_view', 'list')
            ->set('drive_sort', 'updated_at')
            ->set('drive_sort_dir', 'desc')
            ->call('save')
            ->assertHasNoErrors();

        $prefs = $this->employee->fresh()->preferences();

        $this->assertSame('drive', $prefs->landing());
        $this->assertSame(50, $prefs->rowsPerPage());
        $this->assertSame('Y-m-d', $prefs->dateFormat());
        $this->assertSame('list', $prefs->driveView());
        $this->assertSame('desc', $prefs->driveSortDirection());
    }

    public function test_a_value_outside_the_offered_set_is_refused(): void
    {
        Livewire::actingAs($this->employee)
            ->test(PreferenceSettings::class)
            ->set('date_format', 'Y-m-d\'; drop table users; --')
            ->set('rows_per_page', 9999)
            ->set('landing', 'admin.users.index')
            ->call('save')
            ->assertHasErrors(['date_format', 'rows_per_page', 'landing']);
    }

    /**
     * The bag is written by two screens. Saving on one must not quietly reset
     * what was chosen on the other.
     */
    public function test_saving_preferences_leaves_the_notification_choices_alone(): void
    {
        Livewire::actingAs($this->employee)
            ->test(NotificationSettings::class)
            ->set('digest_email', false)
            ->call('save');

        Livewire::actingAs($this->employee)
            ->test(PreferenceSettings::class)
            ->set('rows_per_page', 100)
            ->call('save');

        $prefs = $this->employee->fresh()->preferences();

        $this->assertSame(100, $prefs->rowsPerPage());
        $this->assertFalse($prefs->wantsDigestEmail());
    }

    public function test_resetting_puts_the_display_choices_back_but_keeps_the_digest_choice(): void
    {
        Livewire::actingAs($this->employee)
            ->test(NotificationSettings::class)
            ->set('digest_email', false)
            ->call('save');

        Livewire::actingAs($this->employee)
            ->test(PreferenceSettings::class)
            ->set('rows_per_page', 100)
            ->call('save')
            ->call('resetToDefaults');

        $prefs = $this->employee->fresh()->preferences();

        $this->assertSame(UserPreferences::defaults()['rows_per_page'], $prefs->rowsPerPage());
        $this->assertFalse($prefs->wantsDigestEmail());
    }

    /**
     * A bag written by an older version of the app, or edited by hand, must not
     * be able to put an unknown column into an order-by or an arbitrary string
     * into a date() format.
     */
    public function test_a_hand_edited_preferences_column_falls_back_to_the_defaults(): void
    {
        $this->employee->forceFill(['preferences' => [
            'date_format' => 'evil',
            'rows_per_page' => 'lots',
            'drive_sort' => 'password',
            'landing' => 'settings.system',
            'unknown_key' => 'ignored',
        ]])->save();

        $prefs = $this->employee->fresh()->preferences();
        $defaults = UserPreferences::defaults();

        $this->assertSame($defaults['date_format'], $prefs->dateFormat());
        $this->assertSame($defaults['rows_per_page'], $prefs->rowsPerPage());
        $this->assertSame($defaults['drive_sort'], $prefs->driveSort());
        $this->assertSame($defaults['landing'], $prefs->landing());
        $this->assertArrayNotHasKey('unknown_key', $prefs->toArray());
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    public function test_notification_choices_are_saved(): void
    {
        Livewire::actingAs($this->employee)
            ->test(NotificationSettings::class)
            ->set('digest_email', false)
            ->set('digest_office_summary', false)
            ->call('save')
            ->assertHasNoErrors();

        $prefs = $this->employee->fresh()->preferences();

        $this->assertFalse($prefs->wantsDigestEmail());
        $this->assertFalse($prefs->wantsOfficeSummary());
    }

    public function test_the_office_summary_switch_is_offered_only_to_someone_who_would_get_one(): void
    {
        // Ordinary staff cannot receive documents, so their digest has no
        // office section to leave out.
        $staffView = Livewire::actingAs($this->employee)->test(NotificationSettings::class);
        $this->assertFalse($staffView->viewData('canSeeOfficeSummary'));

        $clerkView = Livewire::actingAs($this->user(RoleEnum::ReceivingClerk))->test(NotificationSettings::class);
        $this->assertTrue($clerkView->viewData('canSeeOfficeSummary'));
    }
}
