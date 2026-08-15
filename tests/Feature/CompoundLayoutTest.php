<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\CompoundBuilding;
use App\Models\CompoundDistrict;
use App\Models\Department;
use App\Models\User;
use App\Support\Compound;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arranging the compound.
 *
 * The one write in the drawn half of this system, and the only place either
 * renderer sends anything to the server. Two things are being guarded.
 *
 * **Who may.** It changes the municipality's own picture of itself, so it sits
 * behind settings.manage — the same gate as System settings and Storage.
 *
 * **What arrives.** The editor checks every rule in the browser before it lets
 * go of a building, and that check is feedback rather than enforcement: it
 * makes the ghost turn red, and it is trivially skipped by anybody willing to
 * open a console. Every one of those rules is therefore checked again here,
 * against a request that has ignored all of them.
 */
class CompoundLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Every test below is about where a building may stand *within* the
        // compound, so the compound is a decent square of ground unless a test
        // says otherwise. The one that is about the boundary takes it back.
        $this->openTheLand();
    }

    /**
     * A square of ground to work on.
     *
     * Four blocks each way, which was the whole of the compound back when it
     * had a fixed size. It has none now — it grows outwards for as long as
     * anybody keeps taking land in — so a test that wants somewhere to put a
     * building has to say how much ground it wants.
     */
    private function openTheLand(int $across = 4): void
    {
        for ($dx = 0; $dx < $across; $dx++) {
            for ($dy = 0; $dy < $across; $dy++) {
                CompoundDistrict::firstOrCreate(['dx' => $dx, 'dy' => $dy]);
            }
        }
    }

    private function building(int $gx, int $gy, string $code = 'MO', int $w = 2, int $h = 2): CompoundBuilding
    {
        $office = Department::factory()->create(['code' => $code, 'short_name' => $code.' Office']);

        return CompoundBuilding::create([
            'department_id' => $office->getKey(),
            'gx' => $gx,
            'gy' => $gy,
            'w' => $w,
            'h' => $h,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        return $user;
    }

    /** A cell of open grass, found rather than assumed. */
    private function grass(int $w = 2, int $h = 2, int $skip = 0): array
    {
        [$cols, $rows] = Compound::extent();

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                if (Compound::isOpenGround($x, $y, $w, $h) && $skip-- <= 0) {
                    return [$x, $y];
                }
            }
        }

        $this->fail('the compound has no open ground at all');
    }

    /*
    |--------------------------------------------------------------------------
    | Who may
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_may_not_rearrange_the_compound(): void
    {
        $building = $this->building(0, 0);

        $this->patchJson(route('compound.layout'), [
            'buildings' => [['id' => $building->id, 'gx' => 2, 'gy' => 2]],
        ])->assertUnauthorized();

        $this->assertSame(0, $building->fresh()->gx);
    }

    public function test_an_ordinary_employee_may_not_rearrange_the_compound(): void
    {
        $building = $this->building(0, 0);

        $this->actingAs($this->clerk())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => $building->id, 'gx' => 2, 'gy' => 2]],
            ])
            ->assertForbidden();

        $this->assertSame(0, $building->fresh()->gx);
    }

    /*
    |--------------------------------------------------------------------------
    | What arrives
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_move_a_building(): void
    {
        $building = $this->building(...$this->grass());
        [$gx, $gy] = $this->grass(skip: 40);

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => $building->id, 'gx' => $gx, 'gy' => $gy]],
            ])
            ->assertOk()
            ->assertJson(['saved' => 1]);

        $building->refresh();

        $this->assertSame($gx, $building->gx);
        $this->assertSame($gy, $building->gy);
    }

    public function test_the_move_is_written_to_the_audit_trail(): void
    {
        $building = $this->building(...$this->grass());
        [$gx, $gy] = $this->grass(skip: 40);

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => $building->id, 'gx' => $gx, 'gy' => $gy]],
            ])
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('event', 'compound.rearranged')->exists(),
            'rearranging the compound should leave a trail',
        );
    }

    /**
     * The whole footprint has to be on the compound's own ground.
     *
     * The near corner passing the field rules is not enough — a two-cell
     * building whose near corner is the last cell has its far half out in the
     * country, and the country is not the municipality's to build on.
     */
    public function test_a_building_may_not_hang_off_the_edge(): void
    {
        $building = $this->building(...$this->grass());
        $edge = Compound::extent()[0] - 1;

        foreach ([['gx' => $edge, 'gy' => 0], ['gx' => 0, 'gy' => $edge]] as $off) {
            $this->actingAs($this->admin())
                ->patchJson(route('compound.layout'), [
                    'buildings' => [['id' => $building->id] + $off],
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('buildings');
        }
    }

    public function test_a_building_may_not_stand_on_another_one(): void
    {
        [$gx, $gy] = $this->grass();
        $there = $this->building($gx, $gy, 'MO');
        $mover = $this->building(...[...$this->grass(skip: 40), 'MTO']);

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => $mover->id, 'gx' => $gx, 'gy' => $gy]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buildings');

        $this->assertSame($there->gx, $there->fresh()->gx);
        $this->assertNotSame($gx, $mover->fresh()->gx);
    }

    /**
     * Two buildings changing places arrive as one request, and each one is on
     * the other's old cell. Checked as an arrangement rather than one move at a
     * time, or a swap — a perfectly ordinary thing to want — would be refused.
     */
    public function test_two_buildings_may_swap_places(): void
    {
        [$ax, $ay] = $this->grass();
        [$bx, $by] = $this->grass(skip: 40);

        $a = $this->building($ax, $ay, 'MO');
        $b = $this->building($bx, $by, 'MTO');

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [
                    ['id' => $a->id, 'gx' => $bx, 'gy' => $by],
                    ['id' => $b->id, 'gx' => $ax, 'gy' => $ay],
                ],
            ])
            ->assertOk();

        $this->assertSame([$bx, $by], [$a->fresh()->gx, $a->fresh()->gy]);
        $this->assertSame([$ax, $ay], [$b->fresh()->gx, $b->fresh()->gy]);
    }

    /** A refused arrangement changes nothing at all, not even the valid half. */
    public function test_a_refused_arrangement_is_not_half_applied(): void
    {
        [$ax, $ay] = $this->grass();
        [$bx, $by] = $this->grass(skip: 40);

        $good = $this->building($ax, $ay, 'MO');
        $bad = $this->building($bx, $by, 'MTO');
        $blocking = $this->building(...[...$this->grass(skip: 120), 'MHO']);
        [$freeX, $freeY] = $this->grass(skip: 80);

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [
                    ['id' => $good->id, 'gx' => $freeX, 'gy' => $freeY],
                    ['id' => $bad->id, 'gx' => $blocking->gx, 'gy' => $blocking->gy],
                ],
            ])
            ->assertUnprocessable();

        $this->assertSame([$ax, $ay], [$good->fresh()->gx, $good->fresh()->gy]);
        $this->assertSame([$bx, $by], [$bad->fresh()->gx, $bad->fresh()->gy]);
    }

    /**
     * The compound is only as big as the ground taken into it.
     *
     * A building may be dragged anywhere within that and nowhere outside it,
     * which is what makes taking in another block mean something.
     */
    public function test_a_building_may_not_be_dragged_onto_land_nobody_has_taken_in(): void
    {
        CompoundDistrict::query()->delete();
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        $building = $this->building(0, 0);
        $outside = Compound::DISTRICT + 1;

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => $building->id, 'gx' => $outside, 'gy' => $outside]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buildings');

        $this->assertSame(0, $building->fresh()->gx);
    }

    public function test_it_refuses_a_building_that_does_not_exist(): void
    {
        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => 9_999, 'gx' => 1, 'gy' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buildings.0.id');
    }

    public function test_it_refuses_a_cell_outside_the_grid(): void
    {
        $building = $this->building(...$this->grass());

        $this->actingAs($this->admin())
            ->patchJson(route('compound.layout'), [
                'buildings' => [['id' => $building->id, 'gx' => Compound::MAX + 5, 'gy' => 0]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buildings.0.gx');
    }
}
