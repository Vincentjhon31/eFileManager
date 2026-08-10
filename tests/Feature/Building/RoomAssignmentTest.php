<?php

namespace Tests\Feature\Building;

use App\Enums\Role as RoleEnum;
use App\Enums\RoomType;
use App\Livewire\Admin\Rooms;
use App\Models\Building;
use App\Models\Department;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Saying which office works in which room.
 *
 * The screen exists because a floor plan and an org chart are drawn by
 * different people, and only the LGU can reconcile them. What it must not do is
 * let that reconciliation happen quietly or by the wrong hands: an office on
 * the wrong door is visible to the whole hall.
 */
class RoomAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Floor $floor;

    private Room $room;

    private Department $budget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $hall = Building::factory()->create(['code' => 'HALL']);
        $this->floor = Floor::factory()->drawn()->create([
            'building_id' => $hall->id, 'level' => 2, 'slug' => 'hall-second-floor',
        ]);

        $this->room = Room::factory()->onFloor($this->floor)->create([
            'name' => 'Office of the Municipal Administrator',
            'svg_shape_id' => 'room-municipal-administrator',
        ]);

        $this->budget = Department::factory()->onboarded()->create(['code' => 'MBO', 'short_name' => 'Budget']);
    }

    private function user(RoleEnum $role): User
    {
        $user = User::factory()->inDepartment($this->budget)->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_only_someone_who_manages_offices_may_map_rooms(): void
    {
        $this->actingAs($this->user(RoleEnum::ReceivingClerk))->get(route('admin.rooms.index'))->assertForbidden();
        $this->actingAs($this->user(RoleEnum::DepartmentAdmin))->get(route('admin.rooms.index'))->assertForbidden();
        $this->actingAs($this->user(RoleEnum::SuperAdmin))->get(route('admin.rooms.index'))->assertOk();
    }

    public function test_an_administrator_maps_a_room_to_an_office(): void
    {
        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(Rooms::class)
            ->call('edit', $this->room->id)
            ->set('department_id', $this->budget->id)
            ->set('room_no', '204')
            ->call('save')
            ->assertHasNoErrors();

        $this->room->refresh();

        $this->assertSame($this->budget->id, $this->room->department_id);
        $this->assertSame('204', $this->room->room_no);
    }

    /** Getting this wrong puts the wrong office on a wall display. */
    public function test_mapping_a_room_is_written_to_the_audit_trail(): void
    {
        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(Rooms::class)
            ->call('edit', $this->room->id)
            ->set('department_id', $this->budget->id)
            ->call('save');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'room.assigned',
            'auditable_id' => $this->room->id,
        ]);
    }

    public function test_a_room_can_be_left_deliberately_unassigned(): void
    {
        $this->room->update(['department_id' => $this->budget->id]);

        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(Rooms::class)
            ->call('edit', $this->room->id)
            ->set('department_id', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($this->room->fresh()->department_id);
    }

    /**
     * The two rooms the plan labels but the seeded roster has no code for are
     * meant to arrive unassigned. That emptiness is a finding — the roster is
     * incomplete — and the screen says so rather than hiding it.
     */
    public function test_the_screen_counts_offices_still_waiting_to_be_mapped(): void
    {
        Room::factory()->onFloor($this->floor)->create(['name' => 'Office of the Secretary']);
        Room::factory()->onFloor($this->floor)->type(RoomType::Utility)->create(['name' => 'Pantry']);

        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(Rooms::class)
            // Two offices unmapped; the pantry belongs to nobody by nature and
            // is not counted as a gap.
            ->assertSee('2 offices on this floor')
            ->assertSee('Not assigned');
    }

    public function test_the_shape_id_is_shown_but_not_editable(): void
    {
        Livewire::actingAs($this->user(RoleEnum::SuperAdmin))
            ->test(Rooms::class)
            ->assertSee('room-municipal-administrator')
            // The drawing owns the shapes; this screen owns the occupants.
            ->assertDontSee('wire:model="svg_shape_id"', false);
    }
}
