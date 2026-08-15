<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\CompoundBuilding;
use App\Models\CompoundDistrict;
use App\Models\CompoundTile;
use App\Models\Department;
use App\Models\User;
use App\Support\Compound;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking in ground, and putting buildings up on it.
 *
 * The compound became a picture of the offices, then the way they are arranged,
 * and this is the step after that: the way one is added. Two permissions are in
 * play and the difference between them is the thing most worth asserting —
 * **settings.manage** puts a building up for an office that already exists,
 * because that is a drawing decision, and only **departments.manage** brings a
 * whole new office into being, because its code goes inside every tracking
 * number that office will ever issue.
 */
class CompoundBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(array $roles = [RoleEnum::SuperAdmin]): User
    {
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        return $user;
    }

    /** Somebody who may arrange the compound but not invent offices. */
    private function arranger(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);
        $user->givePermissionTo(Permission::SettingsManage->value);

        return $user;
    }

    private function openTheFirstBlock(): void
    {
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);
    }

    private function template(string $id = 'office', array $extra = []): array
    {
        return array_merge([
            'template' => $id,
            'gx' => 0,
            'gy' => 0,
            'wall' => Compound::palette()[0]['wall'],
            'roof' => Compound::palette()[0]['roof'],
        ], $extra);
    }

    /*
    |--------------------------------------------------------------------------
    | Taking in ground
    |--------------------------------------------------------------------------
    */

    public function test_an_ordinary_employee_may_not_take_in_ground(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)
            ->postJson(route('compound.land'), ['dx' => 0, 'dy' => 0])
            ->assertForbidden();

        $this->assertSame(0, CompoundDistrict::count());
    }

    public function test_an_administrator_takes_in_a_block_and_it_is_recorded(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.land'), ['dx' => 1, 'dy' => 0])
            ->assertOk()
            ->assertJsonPath('unlocked', ['0,0', '1,0']);

        $this->assertTrue(Compound::isUnlocked(Compound::DISTRICT, 0));
        $this->assertTrue(AuditLog::query()->where('event', 'compound.land_taken')->exists());
    }

    /**
     * The compound gets bigger, and says so.
     *
     * The point of the whole feature. There is no fixed grid behind this any
     * more — the compound is as big as its blocks — so taking one in past the
     * edge enlarges it, and the answer carries the new size and the ground to
     * fill it so the renderer can move its boundary without a reload.
     */
    public function test_taking_in_a_block_past_the_edge_makes_the_compound_bigger(): void
    {
        $this->openTheFirstBlock();

        $this->assertSame([Compound::DISTRICT, Compound::DISTRICT], Compound::extent());

        $response = $this->actingAs($this->admin())
            ->postJson(route('compound.land'), ['dx' => 1, 'dy' => 0])
            ->assertOk()
            ->assertJsonPath('cols', 2 * Compound::DISTRICT)
            ->assertJsonPath('rows', Compound::DISTRICT);

        $ground = $response->json('ground');

        $this->assertCount(Compound::DISTRICT, $ground);
        $this->assertSame(2 * Compound::DISTRICT, strlen($ground[0]));
    }

    /** And there is always somewhere else to go after that. */
    public function test_there_is_always_another_block_on_offer(): void
    {
        $this->openTheFirstBlock();

        $admin = $this->admin();

        $land = Compound::frontier();

        for ($step = 0; $step < 5; $step++) {
            $this->assertNotEmpty($land, "the compound ran out of room after {$step} blocks");

            $land = $this->actingAs($admin)
                ->postJson(route('compound.land'), ['dx' => $land[0]['dx'], 'dy' => $land[0]['dy']])
                ->assertOk()
                ->json('land');
        }

        $this->assertNotEmpty($land, 'the compound has nowhere left to grow');
    }

    public function test_the_same_block_cannot_be_taken_in_twice(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.land'), ['dx' => 0, 'dy' => 0])
            ->assertUnprocessable();

        $this->assertSame(1, CompoundDistrict::count());
    }

    /**
     * Outwards only.
     *
     * A compound that could annex a block eight kilometres away would be two
     * specks with a vast empty grid between them, and the grid is now as big as
     * the furthest thing in it — so one mistyped coordinate would make the
     * whole compound unusably large, permanently.
     */
    public function test_a_block_the_compound_does_not_touch_is_refused(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.land'), ['dx' => 5, 'dy' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dx');

        $this->assertSame(1, CompoundDistrict::count());
    }

    public function test_a_block_past_the_backstop_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('compound.land'), ['dx' => 99, 'dy' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dx');
    }

    /*
    |--------------------------------------------------------------------------
    | Giving ground back
    |--------------------------------------------------------------------------
    */

    /** An empty block on the outside, and the paving on it, go together. */
    public function test_an_empty_block_on_the_edge_can_be_given_back(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);
        CompoundTile::create(['x' => Compound::DISTRICT + 2, 'y' => 2, 'kind' => 'p']);

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 1, 'dy' => 0])
            ->assertOk()
            ->assertJsonPath('unlocked', ['0,0'])
            ->assertJsonPath('cols', Compound::DISTRICT);

        $this->assertSame(1, CompoundDistrict::count());
        $this->assertSame(0, CompoundTile::count());
        $this->assertTrue(AuditLog::query()->where('event', 'compound.land_given_back')->exists());
    }

    /** Paving inside the compound is not touched by a block going out. */
    public function test_giving_a_block_back_leaves_the_paving_on_every_other_block(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);

        CompoundTile::create(['x' => 2, 'y' => 2, 'kind' => 'r']);
        CompoundTile::create(['x' => Compound::DISTRICT + 2, 'y' => 2, 'kind' => 'p']);

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 1, 'dy' => 0])
            ->assertOk();

        $this->assertSame(1, CompoundTile::count());
        $this->assertSame('r', CompoundTile::sole()->kind);
    }

    /** A building on it is the whole reason this used to be refused outright. */
    public function test_a_block_with_something_standing_on_it_cannot_be_given_back(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);

        CompoundBuilding::create(['sprite' => 'flagpole', 'gx' => Compound::DISTRICT + 1, 'gy' => 1, 'w' => 1, 'h' => 1]);

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 1, 'dy' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dx');

        $this->assertSame(2, CompoundDistrict::count());
    }

    /**
     * A building whose far end reaches into the next block holds that one too.
     *
     * Checked by cell rather than by corner: a three-wide building placed at
     * the edge of one block has two cells in it and one in the next, and giving
     * the next one back would strand a third of a building on ground the
     * municipality does not hold.
     */
    public function test_a_block_a_building_only_reaches_into_cannot_be_given_back(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);

        CompoundBuilding::create([
            'sprite' => 'wall', 'gx' => Compound::DISTRICT - 2, 'gy' => 1, 'w' => 3, 'h' => 1,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 1, 'dy' => 0])
            ->assertUnprocessable();
    }

    /** The compound stays one piece, or the far half becomes unreachable. */
    public function test_a_block_holding_the_compound_together_cannot_be_given_back(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);
        CompoundDistrict::create(['dx' => 2, 'dy' => 0]);

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 1, 'dy' => 0])
            ->assertUnprocessable();

        /* The ends of the same row are both fine — only the middle is load
           bearing. */
        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 2, 'dy' => 0])
            ->assertOk();
    }

    /** A compound with no ground is a blank screen with no way back. */
    public function test_the_last_block_cannot_be_given_back(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.land.destroy'), ['dx' => 0, 'dy' => 0])
            ->assertUnprocessable();

        $this->assertSame(1, CompoundDistrict::count());
    }

    public function test_an_ordinary_employee_may_not_give_ground_back(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)
            ->deleteJson(route('compound.land.destroy'), ['dx' => 1, 'dy' => 0])
            ->assertForbidden();

        $this->assertSame(2, CompoundDistrict::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Changing a building that is already standing
    |--------------------------------------------------------------------------
    */

    public function test_a_building_can_be_redesigned_and_repainted(): void
    {
        $this->openTheFirstBlock();
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);

        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);
        $building = CompoundBuilding::create([
            'department_id' => $office->getKey(), 'gx' => 0, 'gy' => 0,
        ]);

        $colour = Compound::palette()[3];

        $this->actingAs($this->admin())
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'hall',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertOk();

        $building->refresh();

        $this->assertSame('hall', $building->style);
        $this->assertSame(3, $building->w);
        $this->assertSame($colour['roof'], $building->roof);

        /* The office it is for is not something a redesign may change. */
        $this->assertSame($office->getKey(), $building->department_id);
        $this->assertTrue(AuditLog::query()->where('event', 'compound.building_changed')->exists());
    }

    /** Repainting is not "the Mayor's Office is standing where the Mayor's Office is". */
    public function test_a_building_is_not_counted_as_standing_on_itself(): void
    {
        $this->openTheFirstBlock();

        $building = CompoundBuilding::create([
            'sprite' => 'tent', 'gx' => 1, 'gy' => 1, 'w' => 2, 'h' => 2,
        ]);

        $colour = Compound::palette()[1];

        $this->actingAs($this->admin())
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'tent',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertOk();
    }

    /** Growing into a neighbour is refused, the same as placing on one. */
    public function test_a_redesign_that_would_grow_onto_a_neighbour_is_refused(): void
    {
        $this->openTheFirstBlock();

        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);
        $building = CompoundBuilding::create([
            'department_id' => $office->getKey(), 'gx' => 0, 'gy' => 0, 'w' => 2, 'h' => 2,
        ]);

        CompoundBuilding::create(['sprite' => 'shed', 'gx' => 2, 'gy' => 0, 'w' => 2, 'h' => 1]);

        $colour = Compound::palette()[0];

        $this->actingAs($this->admin())
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'warehouse',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gx');

        $this->assertSame(2, $building->refresh()->w);
    }

    /**
     * The two kinds do not cross.
     *
     * Which office a building is for is decided once, when it goes up. Letting
     * a redesign turn the Treasurer's Office into a flagpole — or a flagpole
     * into an office building with no office behind it — would be a change to
     * the directory dressed up as a change to a picture.
     */
    public function test_an_office_building_may_not_be_redesigned_as_scenery(): void
    {
        $this->openTheFirstBlock();

        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);
        $building = CompoundBuilding::create([
            'department_id' => $office->getKey(), 'gx' => 0, 'gy' => 0,
        ]);

        $colour = Compound::palette()[0];

        $this->actingAs($this->admin())
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'flagpole',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template');
    }

    public function test_scenery_may_not_be_redesigned_as_an_office(): void
    {
        $this->openTheFirstBlock();

        $building = CompoundBuilding::create(['sprite' => 'tree', 'gx' => 1, 'gy' => 1, 'w' => 1, 'h' => 1]);

        $colour = Compound::palette()[0];

        $this->actingAs($this->admin())
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'office',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template');

        $this->assertNull($building->refresh()->department_id);
    }

    /** Changing a building is a drawing decision, so the drawing permission does it. */
    public function test_somebody_who_may_arrange_may_change_a_building(): void
    {
        $this->openTheFirstBlock();

        $building = CompoundBuilding::create(['sprite' => 'tent', 'gx' => 1, 'gy' => 1, 'w' => 2, 'h' => 2]);
        $colour = Compound::palette()[2];

        $this->actingAs($this->arranger())
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'shed',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertOk();

        $this->assertSame('shed', $building->refresh()->sprite);
    }

    public function test_an_ordinary_employee_may_not_change_a_building(): void
    {
        $this->openTheFirstBlock();

        $building = CompoundBuilding::create(['sprite' => 'tent', 'gx' => 1, 'gy' => 1, 'w' => 2, 'h' => 2]);
        $colour = Compound::palette()[0];

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)
            ->patchJson(route('compound.buildings.update', $building), [
                'template' => 'shed',
                'wall' => $colour['wall'],
                'roof' => $colour['roof'],
            ])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Putting a building up
    |--------------------------------------------------------------------------
    */

    public function test_a_building_can_be_put_up_for_an_office_that_has_none(): void
    {
        $this->openTheFirstBlock();
        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('hall', [
                'department_id' => $office->getKey(),
            ]))
            ->assertCreated();

        $building = CompoundBuilding::sole();

        $this->assertSame($office->getKey(), $building->department_id);
        $this->assertSame('hall', $building->style);
        $this->assertSame(3, $building->w);
        $this->assertTrue(AuditLog::query()->where('event', 'compound.building_added')->exists());
    }

    /** Scenery has no office behind it and does not ask for one. */
    public function test_scenery_can_be_put_up_with_no_office_at_all(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('flagpole'))
            ->assertCreated();

        $this->assertNull(CompoundBuilding::sole()->department_id);
    }

    public function test_an_office_template_needs_an_office(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->assertSame(0, CompoundBuilding::count());
    }

    public function test_an_office_may_not_have_two_buildings(): void
    {
        $this->openTheFirstBlock();
        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);
        CompoundBuilding::create(['department_id' => $office->getKey(), 'gx' => 4, 'gy' => 4]);

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'department_id' => $office->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_nothing_may_be_put_up_on_ground_nobody_has_taken_in(): void
    {
        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'department_id' => $office->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gx');

        $this->assertSame(0, CompoundBuilding::count());
    }

    public function test_nothing_may_be_put_up_on_top_of_something_else(): void
    {
        $this->openTheFirstBlock();
        CompoundBuilding::create(['gx' => 0, 'gy' => 0, 'sprite' => 'shed']);
        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'department_id' => $office->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gx');
    }

    /*
    |--------------------------------------------------------------------------
    | Bringing a new office into being
    |--------------------------------------------------------------------------
    */

    /**
     * A name in the box is not a decision to create an office.
     *
     * It used to be: the controller inferred "make a new department" from
     * office_name being filled, and a browser quietly autofilling that box
     * brought a whole department into existence during testing. An office code
     * goes inside every tracking number that office ever issues.
     */
    public function test_an_office_is_not_created_without_being_asked_for(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'office_name' => 'Autofilled By The Browser',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->assertSame(0, Department::query()->where('name', 'Autofilled By The Browser')->count());
        $this->assertSame(0, CompoundBuilding::count());
    }

    public function test_a_new_office_can_be_created_along_with_its_building(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'create_office' => true,
                'office_name' => 'Municipal Veterinary Office',
                'office_code' => 'MVET',
            ]))
            ->assertCreated();

        $office = Department::query()->where('code', 'MVET')->sole();

        $this->assertSame('Municipal Veterinary Office', $office->name);
        $this->assertFalse($office->is_external);

        // Not onboarded: an office exists on the chart long before anybody in it
        // has an account, and pretending otherwise routes documents at nobody.
        $this->assertFalse($office->is_onboarded);
        $this->assertSame($office->getKey(), CompoundBuilding::sole()->department_id);
    }

    /** The heavier half of this screen, behind the heavier permission. */
    public function test_arranging_the_compound_is_not_permission_to_invent_offices(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->arranger())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'create_office' => true,
                'office_name' => 'Municipal Veterinary Office',
            ]))
            ->assertForbidden();

        $this->assertSame(0, Department::query()->where('name', 'Municipal Veterinary Office')->count());
    }

    /** But it is permission to put a building up for one that already exists. */
    public function test_arranging_the_compound_is_permission_to_house_an_existing_office(): void
    {
        $this->openTheFirstBlock();
        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);

        $this->actingAs($this->arranger())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'department_id' => $office->getKey(),
            ]))
            ->assertCreated();
    }

    public function test_a_code_already_in_use_is_refused(): void
    {
        $this->openTheFirstBlock();
        Department::factory()->create(['code' => 'MVET', 'is_external' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('compound.buildings.store'), $this->template('office', [
                'create_office' => true,
                'office_name' => 'Another one',
                'office_code' => 'MVET',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('office_code');
    }

    /*
    |--------------------------------------------------------------------------
    | Taking one down
    |--------------------------------------------------------------------------
    */

    /**
     * The building goes; the office stays.
     *
     * A department is a row that documents point at, and "delete the
     * Treasurer's Office" is not something that should be one click away on a
     * map.
     */
    public function test_taking_a_building_down_leaves_the_office_alone(): void
    {
        $office = Department::factory()->create(['code' => 'MO', 'is_external' => false]);
        $building = CompoundBuilding::create(['department_id' => $office->getKey(), 'gx' => 0, 'gy' => 0]);

        $this->actingAs($this->admin())
            ->deleteJson(route('compound.buildings.destroy', $building))
            ->assertOk();

        $this->assertSame(0, CompoundBuilding::count());
        $this->assertNotNull($office->fresh());
        $this->assertTrue(AuditLog::query()->where('event', 'compound.building_removed')->exists());
    }

    public function test_an_ordinary_employee_may_not_take_a_building_down(): void
    {
        $building = CompoundBuilding::create(['gx' => 0, 'gy' => 0, 'sprite' => 'shed']);
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)
            ->deleteJson(route('compound.buildings.destroy', $building))
            ->assertForbidden();

        $this->assertSame(1, CompoundBuilding::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Laying ground
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_lay_a_path_and_lift_it_again(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->patchJson(route('compound.tiles'), [
                'tiles' => [['x' => 1, 'y' => 1, 'kind' => 'r'], ['x' => 2, 'y' => 1, 'kind' => 'p']],
            ])
            ->assertOk();

        $ground = Compound::ground();

        $this->assertSame('r', $ground[1][1]);
        $this->assertSame('p', $ground[1][2]);
        $this->assertTrue(AuditLog::query()->where('event', 'compound.ground_laid')->exists());

        /* Grass is not a surface — it is the absence of one, so laying it takes
           the row out rather than storing six hundred rows saying nothing
           happened here. */
        $this->actingAs($this->admin())
            ->patchJson(route('compound.tiles'), [
                'tiles' => [['x' => 1, 'y' => 1, 'kind' => 'g']],
            ])
            ->assertOk();

        $this->assertSame('g', Compound::ground()[1][1]);
        $this->assertSame(0, CompoundTile::query()->where('x', 1)->where('y', 1)->count());
    }

    public function test_nothing_may_be_laid_on_ground_nobody_has_taken_in(): void
    {
        $this->openTheFirstBlock();
        $outside = Compound::DISTRICT + 1;

        $this->actingAs($this->admin())
            ->patchJson(route('compound.tiles'), [
                'tiles' => [['x' => $outside, 'y' => $outside, 'kind' => 'r']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiles');

        $this->assertSame(0, CompoundTile::count());
    }

    public function test_an_ordinary_employee_may_not_lay_ground(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)
            ->patchJson(route('compound.tiles'), [
                'tiles' => [['x' => 0, 'y' => 0, 'kind' => 'r']],
            ])
            ->assertForbidden();
    }

    public function test_only_the_surfaces_on_offer_may_be_laid(): void
    {
        $this->openTheFirstBlock();

        $this->actingAs($this->admin())
            ->patchJson(route('compound.tiles'), [
                'tiles' => [['x' => 0, 'y' => 0, 'kind' => 'w']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiles.0.kind');
    }

    /*
    |--------------------------------------------------------------------------
    | What the screen is handed
    |--------------------------------------------------------------------------
    */

    /** A padlock over a field nobody can do anything about is a locked door. */
    public function test_only_somebody_who_could_take_land_in_is_shown_that_there_is_any(): void
    {
        $this->actingAs($this->admin());
        $this->assertNotEmpty(Compound::payload()['land']);
        $this->assertNotEmpty(Compound::payload()['templates']);

        $clerk = User::factory()->create();
        $clerk->assignRole(RoleEnum::Staff->value);

        $this->actingAs($clerk);
        $this->assertSame([], Compound::payload()['land']);
        $this->assertSame([], Compound::payload()['templates']);
        $this->assertSame([], Compound::payload()['palette']);
    }

    /**
     * Every key the renderer reads is a key PHP sends.
     *
     * The one seam in this whole screen: App\Support\Compound decides what is
     * in the payload and resources/js/compound.js decides what to do with it,
     * and nothing but agreement holds the two together. It has come apart twice
     * — once when a fixed grid was replaced by cols and rows and the renderer
     * went on reading `grid`, and once when a function that took an index was
     * changed to take a block and a caller went on passing the number. Both
     * showed up as a drawing that was subtly wrong or a server error nobody
     * could read back to a cause.
     *
     * A rename on either side now fails here instead, which is a test failure
     * naming the key rather than a compound drawn one block wide.
     */
    public function test_the_renderer_reads_nothing_the_payload_does_not_send(): void
    {
        $this->openTheFirstBlock();
        $this->actingAs($this->admin());

        $js = file_get_contents(resource_path('js/compound.js'));

        preg_match_all('/\bdata\.([a-zA-Z][a-zA-Z0-9]*)/', $js, $found);

        $keys = array_unique($found[1]);
        $payload = Compound::payload();

        $this->assertNotEmpty($keys, 'the renderer reads nothing at all, which cannot be right');

        foreach ($keys as $key) {
            $this->assertArrayHasKey(
                $key,
                $payload,
                "compound.js reads data.{$key}, which Compound::payload() does not send",
            );
        }
    }

    public function test_every_template_names_a_style_and_a_sprite_the_renderer_has(): void
    {
        $js = file_get_contents(resource_path('js/compound.js'));

        foreach (Compound::templates() as $template) {
            if ($template['sprite'] !== 'office') {
                $this->assertMatchesRegularExpression(
                    '/^\s{4}'.preg_quote($template['sprite'], '/').'\(c, b/m',
                    $js,
                    'compound.js has no scenery named '.$template['sprite'],
                );
            }

            if ($template['style'] !== 'plain') {
                $this->assertStringContainsString(
                    "b.style === '".$template['style']."'",
                    $js,
                    'compound.js does not draw the '.$template['style'].' style',
                );
            }
        }
    }
}
