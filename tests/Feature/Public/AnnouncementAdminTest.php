<?php

namespace Tests\Feature\Public;

use App\Enums\AnnouncementCategory;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\Announcements;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The notices screen: one permission gates writing and publishing alike, and
 * the two-step confirmation is the only thing standing between a draft and the
 * whole town reading it.
 */
class AnnouncementAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function officer(): User
    {
        $user = User::factory()->inDepartment(Department::factory()->onboarded()->create())->create();
        $user->givePermissionTo(Permission::PublicPublish->value);

        return $user;
    }

    private function ordinaryStaff(): User
    {
        $user = User::factory()->inDepartment(Department::factory()->onboarded()->create())->create();
        $user->assignRole(RoleEnum::DepartmentAdmin->value);

        return $user;
    }

    public function test_someone_without_the_permission_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->ordinaryStaff())->get(route('admin.announcements.index'))->assertForbidden();
        $this->actingAs($this->officer())->get(route('admin.announcements.index'))->assertOk();
    }

    /**
     * A department administrator manages users and folders in their office —
     * that permission alone must not carry the authority to speak for the
     * municipality in public.
     */
    public function test_a_department_administrator_has_no_publishing_authority_by_default(): void
    {
        $this->actingAs($this->ordinaryStaff())
            ->get(route('admin.announcements.index'))
            ->assertForbidden();
    }

    public function test_saving_a_notice_creates_a_draft_not_a_live_page(): void
    {
        $officer = $this->officer();

        Livewire::actingAs($officer)
            ->test(Announcements::class)
            ->call('create')
            ->set('title', 'Suspension of work')
            ->set('category', AnnouncementCategory::Advisory->value)
            ->set('body', 'Classes are suspended due to weather.')
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::where('title', 'Suspension of work')->firstOrFail();
        $this->assertFalse($announcement->isLive());

        $this->get(route('public.announcements'))->assertDontSee('Suspension of work');
    }

    public function test_publishing_requires_a_second_confirming_step(): void
    {
        $officer = $this->officer();
        $announcement = Announcement::factory()->create(['title' => 'Fiesta schedule']);

        $component = Livewire::actingAs($officer)->test(Announcements::class);

        // The button alone does nothing without confirmation.
        $component->call('confirm', 'publish', $announcement->id)
            ->assertSet('confirmingId', $announcement->id);

        $this->assertFalse($announcement->fresh()->isLive());

        $component->call('publish')->assertHasNoErrors();

        $this->assertTrue($announcement->fresh()->isLive());
    }

    public function test_withdrawing_requires_a_reason(): void
    {
        $officer = $this->officer();
        $announcement = Announcement::factory()->published()->create();

        Livewire::actingAs($officer)
            ->test(Announcements::class)
            ->call('confirm', 'withdraw', $announcement->id)
            ->call('withdraw')
            ->assertHasErrors('reason');

        $this->assertTrue($announcement->fresh()->isLive());
    }

    public function test_a_refusal_from_the_publication_service_is_shown_as_a_readable_message(): void
    {
        $officer = $this->officer();
        $announcement = Announcement::factory()->create(['body' => '']);

        Livewire::actingAs($officer)
            ->test(Announcements::class)
            ->call('confirm', 'publish', $announcement->id)
            ->call('publish')
            ->assertHasErrors('publication');
    }
}
