<?php

namespace Tests\Feature\Workspace;

use App\Enums\Role as RoleEnum;
use App\Enums\WorkspaceAppScope;
use App\Enums\WorkspaceAppStatus;
use App\Livewire\Workspace\Index as Workspace;
use App\Models\Department;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Models\WorkspaceApp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The workspace: an office's apps and a preview of its files, behind one
 * search box.
 */
class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Department $mayor;

    private Department $treasury;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->treasury = Department::factory()->onboarded()->create(['code' => 'MTO', 'short_name' => 'Treasury']);
        $this->clerk = $this->staff($this->mayor);
    }

    private function staff(Department $office, RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    */

    public function test_the_workspace_shows_this_offices_apps_but_not_another_offices(): void
    {
        WorkspaceApp::factory()->forOffice($this->mayor)->create(['name' => 'AI WorkLog']);
        WorkspaceApp::factory()->forOffice($this->treasury)->create(['name' => 'Revenue Collection']);

        Livewire::actingAs($this->clerk)
            ->test(Workspace::class)
            ->call('switchTab', 'apps')
            ->assertSee('AI WorkLog')
            ->assertDontSee('Revenue Collection');
    }

    public function test_organization_wide_and_public_apps_are_visible_to_every_office(): void
    {
        WorkspaceApp::factory()->forOffice($this->treasury)->orgWide()->create(['name' => 'Schedule Borrowing System']);
        WorkspaceApp::factory()->openToPublic()->create(['name' => 'Downloadable Forms', 'department_id' => null]);

        Livewire::actingAs($this->clerk)
            ->test(Workspace::class)
            ->call('switchTab', 'apps')
            ->assertSee('Schedule Borrowing System')
            ->assertSee('Downloadable Forms');
    }

    public function test_retired_apps_never_show(): void
    {
        WorkspaceApp::factory()->forOffice($this->mayor)->retired()->create(['name' => 'Old Leave System']);

        Livewire::actingAs($this->clerk)
            ->test(Workspace::class)
            ->call('switchTab', 'apps')
            ->assertDontSee('Old Leave System');
    }

    public function test_a_user_with_no_department_cannot_open_the_workspace(): void
    {
        $unassigned = User::factory()->create(['department_id' => null]);
        $unassigned->assignRole(RoleEnum::Staff->value);

        $this->actingAs($unassigned)->get(route('workspace'))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function test_the_office_filter_hides_org_wide_apps(): void
    {
        WorkspaceApp::factory()->forOffice($this->mayor)->create(['name' => 'AI WorkLog']);
        WorkspaceApp::factory()->forOffice($this->treasury)->orgWide()->create(['name' => 'Schedule Borrowing System']);

        Livewire::actingAs($this->clerk)
            ->test(Workspace::class)
            ->call('switchTab', 'apps')
            ->call('filterApps', 'office')
            ->assertSee('AI WorkLog')
            ->assertDontSee('Schedule Borrowing System');
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    public function test_searching_the_workspace_matches_both_apps_and_files(): void
    {
        WorkspaceApp::factory()->forOffice($this->mayor)->create([
            'name' => 'Business Permits (BPLS)',
            'description' => 'New and renewal applications with assessment.',
        ]);

        $folder = Folder::factory()->forOffice($this->mayor)->create();
        File::factory()->in($folder)->create(['name' => 'Business Permit Renewal Form.pdf']);
        File::factory()->in($folder)->create(['name' => 'Unrelated Memo.docx']);

        Livewire::actingAs($this->clerk)
            ->test(Workspace::class)
            ->set('search', 'business permit')
            ->assertSee('Business Permits (BPLS)')
            ->assertSee('Business Permit Renewal Form.pdf')
            ->assertDontSee('Unrelated Memo.docx');
    }

    public function test_search_still_respects_office_visibility(): void
    {
        $theirs = Folder::factory()->forOffice($this->treasury)->create();
        File::factory()->in($theirs)->create(['name' => 'Confidential Assessment.pdf']);

        Livewire::actingAs($this->clerk)
            ->test(Workspace::class)
            ->set('search', 'confidential')
            ->assertDontSee('Confidential Assessment.pdf');
    }

    /*
    |--------------------------------------------------------------------------
    | Home
    |--------------------------------------------------------------------------
    */

    public function test_home_previews_a_handful_of_apps_and_recent_files(): void
    {
        WorkspaceApp::factory()->forOffice($this->mayor)->create(['name' => 'AI WorkLog', 'status' => WorkspaceAppStatus::Live, 'scope' => WorkspaceAppScope::Department]);

        $folder = Folder::factory()->forOffice($this->mayor)->create();
        File::factory()->in($folder)->create(['name' => 'Organizational Chart 2026.pdf']);

        $this->actingAs($this->clerk)->get(route('workspace'))
            ->assertOk()
            ->assertSee('AI WorkLog')
            ->assertSee('Organizational Chart 2026.pdf');
    }
}
