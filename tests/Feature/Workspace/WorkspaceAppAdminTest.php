<?php

namespace Tests\Feature\Workspace;

use App\Enums\Role as RoleEnum;
use App\Enums\WorkspaceAppScope;
use App\Enums\WorkspaceAppStatus;
use App\Livewire\Admin\WorkspaceApps;
use App\Livewire\Workspace\Index as WorkspaceIndex;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceApp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Curating the workspace catalog.
 *
 * Two lines run through all of it. An office administrator lists what their own
 * office runs; reaching every office — or the public — needs the settings
 * permission on top, because an org-wide entry appears on everybody's workspace
 * and a public one is a link the municipality is putting its name to.
 *
 * And the URL: staff are invited to click these from a government system, so a
 * catalog entry that could carry a javascript: address would be a stored
 * cross-site scripting hole wearing the municipality's badge.
 */
class WorkspaceAppAdminTest extends TestCase
{
    use RefreshDatabase;

    private Department $mayor;

    private Department $budget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->budget = Department::factory()->onboarded()->create(['code' => 'MBO', 'short_name' => 'Budget']);
    }

    private function user(RoleEnum $role, ?Department $office = null): User
    {
        $user = User::factory()->inDepartment($office ?? $this->mayor)->create();
        $user->assignRole($role->value);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Reaching the screen
    |--------------------------------------------------------------------------
    */

    public function test_an_office_administrator_reaches_the_catalog(): void
    {
        $this->actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->get(route('admin.apps.index'))
            ->assertOk();
    }

    public function test_ordinary_staff_cannot_reach_the_catalog(): void
    {
        $this->actingAs($this->user(RoleEnum::Staff))
            ->get(route('admin.apps.index'))
            ->assertForbidden();
    }

    public function test_a_receiving_clerk_cannot_reach_the_catalog(): void
    {
        $this->actingAs($this->user(RoleEnum::ReceivingClerk))
            ->get(route('admin.apps.index'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Adding
    |--------------------------------------------------------------------------
    */

    public function test_an_office_administrator_adds_an_app_for_their_own_office(): void
    {
        $admin = $this->user(RoleEnum::DepartmentAdmin);

        Livewire::actingAs($admin)
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Business Permit System')
            ->set('url', 'https://permits.bongabong.gov.ph')
            ->set('description', 'Where permit applications are encoded.')
            ->set('icon_glyph', 'BP')
            ->call('save')
            ->assertHasNoErrors();

        $app = WorkspaceApp::firstWhere('name', 'Business Permit System');

        $this->assertNotNull($app);
        $this->assertSame($this->mayor->id, $app->department_id);
        $this->assertSame(WorkspaceAppScope::Department, $app->scope);
        $this->assertSame('business-permit-system', $app->slug);
        $this->assertSame($admin->id, $app->created_by);

        $this->assertDatabaseHas('audit_logs', ['event' => 'workspace_app.created']);
    }

    public function test_a_new_app_shows_up_on_the_workspace(): void
    {
        $admin = $this->user(RoleEnum::DepartmentAdmin);

        Livewire::actingAs($admin)
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Payroll Portal')
            ->set('url', 'https://payroll.bongabong.gov.ph')
            ->set('status', WorkspaceAppStatus::Live->value)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test(WorkspaceIndex::class)
            ->call('switchTab', 'apps')
            ->assertSee('Payroll Portal');
    }

    public function test_two_apps_with_the_same_name_get_different_slugs(): void
    {
        $admin = $this->user(RoleEnum::SuperAdmin);

        foreach ([1, 2] as $ignored) {
            Livewire::actingAs($admin)
                ->test(WorkspaceApps::class)
                ->call('create')
                ->set('name', 'Records System')
                ->set('url', 'https://records.bongabong.gov.ph')
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, WorkspaceApp::where('name', 'Records System')->count());
        $this->assertSame(2, WorkspaceApp::distinct()->count('slug'));
    }

    /*
    |--------------------------------------------------------------------------
    | The URL
    |--------------------------------------------------------------------------
    */

    public static function dangerousUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'data uri' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file' => ['file:///etc/passwd'],
            'relative path' => ['/admin/users'],
            'bare words' => ['ask the MIS office'],
        ];
    }

    #[DataProvider('dangerousUrls')]
    public function test_only_a_real_web_address_may_be_published(string $url): void
    {
        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Something')
            ->set('url', $url)
            ->call('save')
            ->assertHasErrors('url');

        $this->assertSame(0, WorkspaceApp::count());
    }

    /*
    |--------------------------------------------------------------------------
    | How far an app may reach
    |--------------------------------------------------------------------------
    */

    public function test_an_office_administrator_is_offered_only_their_own_office(): void
    {
        $screen = Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))->test(WorkspaceApps::class);

        $this->assertFalse($screen->viewData('canPublishWidely'));
        $this->assertSame([WorkspaceAppScope::Department], $screen->viewData('scopes'));
    }

    /** The form offering one thing is presentation; this is the rule. */
    public function test_an_office_administrator_cannot_publish_to_every_office_by_posting_it(): void
    {
        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Sneaky App')
            ->set('url', 'https://example.gov.ph')
            ->set('scope', WorkspaceAppScope::Organization->value)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, WorkspaceApp::count());
    }

    public function test_an_office_administrator_cannot_publish_publicly_by_posting_it(): void
    {
        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Sneaky Public App')
            ->set('url', 'https://example.gov.ph')
            ->set('scope', WorkspaceAppScope::Public->value)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, WorkspaceApp::count());
    }

    public function test_an_office_administrator_cannot_file_an_app_under_another_office(): void
    {
        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Not Mine')
            ->set('url', 'https://example.gov.ph')
            ->set('department_id', $this->budget->id)
            ->call('save')
            ->assertHasNoErrors();

        // Silently filed under their own office rather than refused: the field
        // is disabled for them, so a posted value is noise, not an instruction.
        $this->assertSame($this->mayor->id, WorkspaceApp::first()->department_id);
    }

    public function test_the_system_administrator_may_publish_to_every_office(): void
    {
        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(WorkspaceApps::class)
            ->call('create')
            ->set('name', 'Municipal Website CMS')
            ->set('url', 'https://cms.bongabong.gov.ph')
            ->set('scope', WorkspaceAppScope::Organization->value)
            ->set('department_id', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(WorkspaceAppScope::Organization, WorkspaceApp::first()->scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing what somebody else published
    |--------------------------------------------------------------------------
    */

    /**
     * The screen lists every app to a system administrator, so every one of
     * them has to open.
     *
     * The first version of this policy only allowed a department-scoped app to
     * be edited by its own office — which meant a system administrator was
     * shown a full catalog and refused on everything in it that belonged to
     * somebody else. A 403 on a button the screen itself drew is a bug in the
     * screen, not a security decision.
     */
    public function test_the_system_administrator_can_edit_another_offices_app(): void
    {
        $theirs = WorkspaceApp::factory()->create([
            'name' => 'Budget Tracker',
            'scope' => WorkspaceAppScope::Department,
            'department_id' => $this->budget->id,
        ]);

        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(WorkspaceApps::class)
            ->call('edit', $theirs->id)
            ->assertHasNoErrors()
            ->assertSet('name', 'Budget Tracker')
            ->set('name', 'Budget Tracker v2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Budget Tracker v2', $theirs->fresh()->name);
    }

    /** Everything the catalog offers a system administrator must be openable. */
    public function test_every_row_the_catalog_lists_can_be_opened_by_whoever_sees_it(): void
    {
        $admin = $this->user(RoleEnum::SuperAdmin);

        $apps = collect([
            WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Department, 'department_id' => $this->mayor->id]),
            WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Department, 'department_id' => $this->budget->id]),
            WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Organization, 'department_id' => null]),
            WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Public, 'department_id' => $this->budget->id]),
        ]);

        $listed = Livewire::actingAs($admin)->test(WorkspaceApps::class)->viewData('apps');

        $this->assertSame($apps->count(), $listed->total());

        foreach ($listed as $app) {
            $this->assertTrue(
                $admin->can('update', $app),
                "The catalog lists “{$app->name}” but refuses to open it.",
            );
        }
    }

    public function test_the_system_administrator_can_retire_another_offices_app(): void
    {
        $theirs = WorkspaceApp::factory()->create([
            'scope' => WorkspaceAppScope::Department,
            'department_id' => $this->budget->id,
            'status' => WorkspaceAppStatus::Live,
        ]);

        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(WorkspaceApps::class)
            ->call('toggleRetired', $theirs->id)
            ->assertHasNoErrors();

        $this->assertSame(WorkspaceAppStatus::Retired, $theirs->fresh()->status);
    }

    public function test_an_office_administrator_cannot_edit_another_offices_app(): void
    {
        $theirs = WorkspaceApp::factory()->create([
            'scope' => WorkspaceAppScope::Department,
            'department_id' => $this->budget->id,
        ]);

        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('edit', $theirs->id)
            ->assertForbidden();
    }

    public function test_an_office_administrator_cannot_edit_an_org_wide_app(): void
    {
        $orgWide = WorkspaceApp::factory()->create([
            'scope' => WorkspaceAppScope::Organization,
            'department_id' => $this->mayor->id,
        ]);

        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('edit', $orgWide->id)
            ->assertForbidden();
    }

    public function test_the_listing_shows_an_office_administrator_only_their_own(): void
    {
        WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Department, 'department_id' => $this->mayor->id, 'name' => 'Mine']);
        WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Department, 'department_id' => $this->budget->id, 'name' => 'Theirs']);
        WorkspaceApp::factory()->create(['scope' => WorkspaceAppScope::Organization, 'name' => 'Everyones']);

        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->assertSee('Mine')
            ->assertDontSee('Theirs')
            ->assertDontSee('Everyones');
    }

    /*
    |--------------------------------------------------------------------------
    | Retiring
    |--------------------------------------------------------------------------
    */

    public function test_retiring_takes_an_app_off_the_workspace_but_not_off_this_screen(): void
    {
        $admin = $this->user(RoleEnum::DepartmentAdmin);

        $app = WorkspaceApp::factory()->create([
            'name' => 'Old Permit Tool',
            'scope' => WorkspaceAppScope::Department,
            'department_id' => $this->mayor->id,
            'status' => WorkspaceAppStatus::Live,
        ]);

        Livewire::actingAs($admin)
            ->test(WorkspaceApps::class)
            ->call('toggleRetired', $app->id)
            ->assertHasNoErrors()
            ->assertSee('Old Permit Tool');

        $this->assertSame(WorkspaceAppStatus::Retired, $app->fresh()->status);

        // Gone from everybody's workspace, still explainable here.
        Livewire::actingAs($admin)
            ->test(WorkspaceIndex::class)
            ->call('switchTab', 'apps')
            ->assertDontSee('Old Permit Tool');

        $this->assertDatabaseHas('audit_logs', ['event' => 'workspace_app.retired']);
    }

    public function test_a_retired_app_can_be_brought_back(): void
    {
        $app = WorkspaceApp::factory()->create([
            'scope' => WorkspaceAppScope::Department,
            'department_id' => $this->mayor->id,
            'status' => WorkspaceAppStatus::Retired,
        ]);

        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('toggleRetired', $app->id);

        $this->assertSame(WorkspaceAppStatus::Pilot, $app->fresh()->status);
    }

    public function test_removing_an_app_entirely_is_recorded(): void
    {
        $app = WorkspaceApp::factory()->create([
            'name' => 'Abandoned Tool',
            'scope' => WorkspaceAppScope::Department,
            'department_id' => $this->mayor->id,
        ]);

        Livewire::actingAs($this->user(RoleEnum::DepartmentAdmin))
            ->test(WorkspaceApps::class)
            ->call('delete', $app->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('workspace_apps', ['id' => $app->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'workspace_app.deleted']);
    }
}
