<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\AuditTrail;
use App\Livewire\Admin\Departments;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }

    public function test_only_a_superadmin_may_manage_offices(): void
    {
        $deptAdmin = User::factory()->create();
        $deptAdmin->assignRole(RoleEnum::DepartmentAdmin->value);

        $this->actingAs($deptAdmin)->get('/admin/offices')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/admin/offices')->assertOk();
    }

    public function test_an_office_code_must_be_unique_and_uppercase(): void
    {
        Department::factory()->create(['code' => 'MO']);

        Livewire::actingAs($this->superAdmin())
            ->test(Departments::class)
            ->call('create')
            ->set('code', 'MO')
            ->set('name', 'Duplicate Office')
            ->call('save')
            ->assertHasErrors(['code' => 'unique']);

        Livewire::actingAs($this->superAdmin())
            ->test(Departments::class)
            ->call('create')
            ->set('code', 'lower case')
            ->set('name', 'Badly Coded Office')
            ->call('save')
            ->assertHasErrors(['code' => 'regex']);
    }

    /**
     * The pilot depends on this: a non-onboarded office is still somewhere a
     * document can legitimately be sent, so it must remain visible to routing.
     */
    public function test_offices_that_are_not_onboarded_remain_routable(): void
    {
        $this->seed(DepartmentSeeder::class);

        $onboarded = Department::where('is_onboarded', true)->get();
        $routable = Department::routable()->get();

        $this->assertCount(1, $onboarded, 'Only the Mayor\'s Office is onboarded at pilot.');
        $this->assertSame('MO', $onboarded->first()->code);
        $this->assertGreaterThan(20, $routable->count(), 'Every office stays routable regardless of onboarding.');
    }

    public function test_an_external_party_cannot_be_onboarded(): void
    {
        $external = Department::factory()->external()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(Departments::class)
            ->call('toggleOnboarded', $external->id)
            ->assertHasErrors('onboard');

        $this->assertFalse($external->fresh()->is_onboarded);
    }

    public function test_onboarding_an_office_is_recorded_in_the_audit_trail(): void
    {
        $department = Department::factory()->create(['code' => 'MBO']);

        Livewire::actingAs($this->superAdmin())
            ->test(Departments::class)
            ->call('toggleOnboarded', $department->id);

        $this->assertTrue($department->fresh()->is_onboarded);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'department.onboarded',
            'auditable_id' => $department->id,
        ]);
    }

    public function test_accepts_digital_receipt_only_when_internal_and_onboarded(): void
    {
        $this->assertTrue(Department::factory()->onboarded()->make()->acceptsDigitalReceipt());
        $this->assertFalse(Department::factory()->make()->acceptsDigitalReceipt());
        $this->assertFalse(Department::factory()->external()->make()->acceptsDigitalReceipt());
    }

    /**
     * An office administrator must not be able to read another office's
     * activity through the audit screen.
     */
    public function test_the_audit_trail_is_scoped_to_the_users_own_office(): void
    {
        $mine = Department::factory()->create();
        $theirs = Department::factory()->create();

        $admin = User::factory()->inDepartment($mine)->create();
        $admin->assignRole(RoleEnum::DepartmentAdmin->value);

        $outsider = User::factory()->inDepartment($theirs)->create();

        $logger = app(AuditLogger::class);
        $logger->log('test.mine', description: 'Visible to me', actor: $admin);
        $logger->log('test.theirs', description: 'Belongs to another office', actor: $outsider);

        Livewire::actingAs($admin)
            ->test(AuditTrail::class)
            ->assertSee('Visible to me')
            ->assertDontSee('Belongs to another office');
    }

    public function test_a_superadmin_sees_every_offices_audit_entries(): void
    {
        $outsider = User::factory()->create();
        app(AuditLogger::class)->log('test.theirs', description: 'Belongs to another office', actor: $outsider);

        Livewire::actingAs($this->superAdmin())
            ->test(AuditTrail::class)
            ->assertSee('Belongs to another office');
    }
}
