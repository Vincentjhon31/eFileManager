<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\Users;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Access boundaries around user management.
 *
 * The risk being guarded here is privilege escalation: a department
 * administrator must not be able to grant themselves system administration,
 * nor reach into another office. Under RA 10173, a Budget clerk gaining
 * visibility of the Mayor's personnel records is a reportable breach, not a
 * cosmetic bug.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function departmentAdmin(Department $department): User
    {
        $user = User::factory()->inDepartment($department)->create();
        $user->assignRole(RoleEnum::DepartmentAdmin->value);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }

    public function test_ordinary_staff_cannot_reach_user_management(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(RoleEnum::Staff->value);

        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
    }

    public function test_a_department_admin_sees_only_their_own_office(): void
    {
        $mine = Department::factory()->onboarded()->create();
        $theirs = Department::factory()->create();

        $admin = $this->departmentAdmin($mine);
        $colleague = User::factory()->inDepartment($mine)->create(['name' => 'Colleague Of Mine']);
        $outsider = User::factory()->inDepartment($theirs)->create(['name' => 'Someone Elsewhere']);

        Livewire::actingAs($admin)
            ->test(Users::class)
            ->assertSee($colleague->name)
            ->assertDontSee($outsider->name);
    }

    public function test_a_department_admin_cannot_edit_a_user_in_another_office(): void
    {
        $admin = $this->departmentAdmin(Department::factory()->create());
        $outsider = User::factory()->inDepartment(Department::factory()->create())->create();

        // The outsider is not merely unauthorized, they are invisible:
        // scopedQuery() filters them out before findOrFail() ever looks.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($admin)
            ->test(Users::class)
            ->call('edit', $outsider->id);
    }

    public function test_a_department_admin_cannot_grant_the_superadmin_role(): void
    {
        $department = Department::factory()->create();
        $admin = $this->departmentAdmin($department);

        $component = Livewire::actingAs($admin)->test(Users::class);

        // The role is not offered in the form...
        $this->assertNotContains(RoleEnum::SuperAdmin->value, $component->instance()->assignableRoles());

        // ...and is rejected by validation if posted anyway.
        $component
            ->call('create')
            ->set('name', 'Ambitious Clerk')
            ->set('email', 'ambitious@bongabong.gov.ph')
            ->set('department_id', $department->id)
            ->set('role', RoleEnum::SuperAdmin->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'ambitious@bongabong.gov.ph']);
    }

    public function test_a_department_admin_cannot_place_a_new_user_in_another_office(): void
    {
        $mine = Department::factory()->create();
        $theirs = Department::factory()->create();
        $admin = $this->departmentAdmin($mine);

        Livewire::actingAs($admin)
            ->test(Users::class)
            ->call('create')
            ->set('name', 'Planted Account')
            ->set('email', 'planted@bongabong.gov.ph')
            ->set('department_id', $theirs->id)
            ->set('role', RoleEnum::Staff->value)
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'planted@bongabong.gov.ph']);
    }

    public function test_a_superadmin_can_create_a_user_and_gets_a_one_time_password(): void
    {
        $department = Department::factory()->onboarded()->create();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(Users::class)
            ->call('create')
            ->set('name', 'Maria Santos')
            ->set('email', 'maria.santos@bongabong.gov.ph')
            ->set('position', 'Records Officer I')
            ->set('department_id', $department->id)
            ->set('role', RoleEnum::ReceivingClerk->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull($component->get('generatedPassword'));

        $created = User::where('email', 'maria.santos@bongabong.gov.ph')->firstOrFail();
        $this->assertTrue($created->hasRole(RoleEnum::ReceivingClerk->value));
        $this->assertTrue($created->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.created',
            'auditable_id' => $created->id,
        ]);
    }

    public function test_a_password_reset_is_logged_without_recording_the_password(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $originalHash = $target->password;

        $component = Livewire::actingAs($admin)
            ->test(Users::class)
            ->call('resetPassword', $target->id);

        $newPassword = $component->get('generatedPassword');

        $this->assertNotNull($newPassword);
        $this->assertNotSame($originalHash, $target->fresh()->password);

        $log = AuditLog::where('event', 'user.password_reset')->firstOrFail();
        $this->assertStringNotContainsString($newPassword, json_encode($log->properties) ?: '');
        $this->assertStringNotContainsString($newPassword, $log->description ?? '');
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(Users::class)
            ->call('toggleActive', $admin->id)
            ->assertHasErrors('active');

        $this->assertTrue($admin->fresh()->is_active);
    }
}
