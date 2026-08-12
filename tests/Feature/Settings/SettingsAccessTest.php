<?php

namespace Tests\Feature\Settings;

use App\Enums\Role as RoleEnum;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may open which settings screen.
 *
 * The account screens need nothing beyond being signed in — a person may always
 * change their own password and decide how their own screens look. System is
 * the exception: it changes the municipality, so it is gated on the same
 * permission as Storage & Backups.
 */
class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->office = Department::factory()->onboarded()->create(['code' => 'MO']);
    }

    private function user(RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($this->office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    public static function accountScreens(): array
    {
        return [
            'profile' => ['settings.profile'],
            'security' => ['settings.security'],
            'preferences' => ['settings.preferences'],
            'notifications' => ['settings.notifications'],
        ];
    }

    #[DataProvider('accountScreens')]
    public function test_any_signed_in_employee_reaches_their_own_settings(string $route): void
    {
        $this->actingAs($this->user())->get(route($route))->assertOk();
    }

    #[DataProvider('accountScreens')]
    public function test_a_signed_out_visitor_is_sent_to_sign_in(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_settings_lands_on_the_profile(): void
    {
        $this->actingAs($this->user())
            ->get('/settings')
            ->assertRedirect('/settings/profile');
    }

    public function test_an_ordinary_employee_cannot_reach_system_settings(): void
    {
        $this->actingAs($this->user())->get(route('settings.system'))->assertForbidden();
    }

    public function test_a_department_administrator_cannot_reach_system_settings(): void
    {
        // Deliberate: a department administrator runs their own office. The
        // upload ceiling and the digest are the municipality's, not theirs.
        $this->actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->get(route('settings.system'))
            ->assertForbidden();
    }

    public function test_the_system_administrator_reaches_system_settings(): void
    {
        $this->actingAs($this->user(RoleEnum::SuperAdmin))
            ->get(route('settings.system'))
            ->assertOk();
    }

    public function test_the_system_tab_is_offered_only_to_someone_who_can_open_it(): void
    {
        $this->actingAs($this->user())
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Preferences')
            ->assertDontSee('System settings');

        $this->actingAs($this->user(RoleEnum::SuperAdmin))
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('System');
    }

    public function test_the_account_menu_offers_settings(): void
    {
        $this->actingAs($this->user())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('settings.profile'), false);
    }
}
