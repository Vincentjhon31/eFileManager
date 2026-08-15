<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Announcement;
use App\Models\CompoundBuilding;
use App\Models\CompoundDistrict;
use App\Models\Department;
use App\Models\User;
use App\Support\Compound;
use App\Support\Navigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The compound draws one building per office of the municipality.
 *
 * It used to draw one per screen the signed-in employee could open, and almost
 * everything worth asserting was about that "could open". The buildings are now
 * the offices themselves, which makes the screen a public directory — so the
 * assertions have moved with it. Two things matter:
 *
 *   1. A guest can read the directory. That is the point of opening it.
 *   2. A guest — and a clerk standing outside their own office — is offered no
 *      door the sidebar would not have offered them. The compound may never
 *      advertise a screen somebody will be turned away from.
 */
class CompoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function office(string $code = 'MO', array $attributes = []): Department
    {
        $office = Department::factory()->create([
            'code' => $code,
            'name' => 'Office of the '.$code,
            'short_name' => $code.' Office',
            'summary' => 'What the '.$code.' does all day.',
            'is_external' => false,
        ] + $attributes);

        CompoundBuilding::create([
            'department_id' => $office->getKey(),
            'gx' => 0,
            'gy' => 0,
            'w' => 2,
            'h' => 2,
            'height' => 26,
        ]);

        return $office->refresh();
    }

    private function staff(?Department $office = null): User
    {
        $user = User::factory()->create(['department_id' => $office?->getKey()]);
        $user->assignRole(RoleEnum::Staff->value);

        return $user;
    }

    private function superAdmin(?Department $office = null): User
    {
        $user = User::factory()->create(['department_id' => $office?->getKey()]);
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Who may look
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_may_read_the_directory(): void
    {
        $this->office();

        $this->get('/compound')
            ->assertOk()
            ->assertSee('The Compound')
            ->assertSee('MO Office')
            ->assertSee('What the MO does all day.');
    }

    public function test_it_renders_for_a_signed_in_employee(): void
    {
        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->get('/compound')
            ->assertOk()
            ->assertSee('The Compound')
            ->assertSee('Dashboard');
    }

    /**
     * The way in is offered to somebody who has not taken it.
     *
     * Both assertions are about the dock along the bottom of the map, which is
     * where every control on this screen lives.
     */
    public function test_a_guest_is_offered_sign_in_rather_than_a_dashboard(): void
    {
        $this->office();

        $this->get('/compound')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Dashboard');
    }

    /*
    |--------------------------------------------------------------------------
    | What the buildings are
    |--------------------------------------------------------------------------
    */

    public function test_the_buildings_are_offices_and_not_the_sidebar_screens(): void
    {
        $this->office('MTO');

        $ids = collect(Compound::places())->pluck('id')->all();

        $this->assertContains('office:MTO', $ids);

        // The screens that used to be buildings. They belong to the sidebar now.
        foreach (['dashboard', 'desk', 'documents', 'drive', 'storage'] as $screen) {
            $this->assertNotContains($screen, $ids);
        }
    }

    public function test_an_office_with_no_building_is_not_in_the_compound(): void
    {
        $placed = $this->office('MO');
        Department::factory()->create(['code' => 'MHO', 'short_name' => 'Health Office']);

        $names = collect(Compound::places())->pluck('name')->all();

        $this->assertContains($placed->displayName(), $names);
        $this->assertNotContains('Health Office', $names);
    }

    public function test_every_building_stands_somewhere_on_the_ground(): void
    {
        $this->office();
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        [$cols, $rows] = Compound::extent();

        foreach (Compound::places() as $place) {
            $this->assertGreaterThanOrEqual(0, $place['gx']);
            $this->assertGreaterThanOrEqual(0, $place['gy']);
            $this->assertLessThanOrEqual($cols, $place['gx'] + $place['w']);
            $this->assertLessThanOrEqual($rows, $place['gy'] + $place['h']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Doors
    |--------------------------------------------------------------------------
    */

    /**
     * The guarantee the whole screen rests on, kept in the one place it still
     * means something: your own office offers exactly the sidebar's links.
     */
    public function test_your_office_offers_only_doors_the_sidebar_would_show(): void
    {
        $office = $this->office();
        $user = $this->superAdmin($office);

        $this->actingAs($user);

        $sidebar = collect(Navigation::forCurrentUser())->pluck('url')->all();
        $mine = collect(Compound::places($user))->firstWhere('id', 'office:MO');

        $this->assertNotEmpty($mine['links']);

        foreach ($mine['links'] as $link) {
            $this->assertContains($link['url'], $sidebar);
        }
    }

    /** A clerk's office offers fewer doors than an administrator's, as it should. */
    public function test_the_doors_are_the_ones_this_account_may_open(): void
    {
        $office = $this->office();

        $clerk = $this->staff($office);
        $this->actingAs($clerk);
        $clerkDoors = collect(Compound::places($clerk))->firstWhere('id', 'office:MO')['links'];

        $admin = $this->superAdmin($office);
        $this->actingAs($admin);
        $adminDoors = collect(Compound::places($admin))->firstWhere('id', 'office:MO')['links'];

        $this->assertLessThan(count($adminDoors), count($clerkDoors));
        $this->assertNotContains('Storage & Backups', array_column($clerkDoors, 'label'));
        $this->assertContains('Storage & Backups', array_column($adminDoors, 'label'));
    }

    public function test_another_office_offers_no_doors_at_all(): void
    {
        $mine = $this->office('MO');
        $this->office('MTO');

        $user = $this->superAdmin($mine);
        $this->actingAs($user);

        $theirs = collect(Compound::places($user))->firstWhere('id', 'office:MTO');

        $this->assertSame([], $theirs['links']);
        $this->assertFalse($theirs['mine']);
    }

    public function test_a_guest_is_offered_no_doors_anywhere(): void
    {
        $this->office('MO');
        $this->office('MTO');

        foreach (Compound::places(null) as $place) {
            $this->assertSame([], $place['links'] ?? []);
        }
    }

    /** The compound does not draw a door onto itself. */
    public function test_no_door_leads_back_to_the_compound(): void
    {
        $office = $this->office();
        $user = $this->superAdmin($office);

        $this->actingAs($user);

        $links = collect(Compound::places($user))->firstWhere('id', 'office:MO')['links'];

        $this->assertNotContains(route('compound'), array_column($links, 'url'));
    }

    /*
    |--------------------------------------------------------------------------
    | What is on the nameplate
    |--------------------------------------------------------------------------
    */

    public function test_an_office_shows_its_head_and_whether_it_is_on_the_system(): void
    {
        $office = $this->office('MO', ['is_onboarded' => true]);
        $head = User::factory()->create(['name' => 'Ana Reyes', 'department_id' => $office->getKey()]);
        $office->update(['head_user_id' => $head->getKey()]);

        $facts = collect(Compound::places())->firstWhere('id', 'office:MO')['facts'];

        $this->assertSame('Ana Reyes', collect($facts)->firstWhere('label', 'Head of office')['value']);
        $this->assertSame('Yes', collect($facts)->firstWhere('label', 'On this system')['value']);
    }

    /** Only live notices, and only that office's. */
    public function test_an_office_lists_what_it_has_posted_and_nothing_it_has_not(): void
    {
        $office = $this->office('MO');
        $other = $this->office('MTO');

        Announcement::factory()->published()->create([
            'title' => 'Office hours this week',
            'department_id' => $office->getKey(),
        ]);
        Announcement::factory()->create([
            'title' => 'Still a draft',
            'department_id' => $office->getKey(),
        ]);
        Announcement::factory()->published()->create([
            'title' => 'Somebody elses notice',
            'department_id' => $other->getKey(),
        ]);

        $notices = collect(Compound::places())->firstWhere('id', 'office:MO')['notices'];
        $titles = array_column($notices, 'title');

        $this->assertContains('Office hours this week', $titles);
        $this->assertNotContains('Still a draft', $titles);
        $this->assertNotContains('Somebody elses notice', $titles);
    }

    /*
    |--------------------------------------------------------------------------
    | The ground
    |--------------------------------------------------------------------------
    */

    /**
     * The compound is exactly as big as the ground taken into it.
     *
     * There is no declared size any more, which is the whole of this change:
     * the compound used to be a fixed square that could be filled but never
     * enlarged, and once its last block was in, that was the municipality for
     * ever. Its extent is now derived from its blocks, so taking one in past
     * the edge makes the compound bigger.
     */
    public function test_the_ground_is_as_big_as_the_land_taken_in(): void
    {
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        $this->assertSame([Compound::DISTRICT, Compound::DISTRICT], Compound::extent());
        $this->assertCount(Compound::DISTRICT, Compound::ground());

        foreach (Compound::ground() as $row) {
            $this->assertSame(Compound::DISTRICT, strlen($row));
        }

        /* One block further east: wider, and no deeper. */
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);

        $this->assertSame([2 * Compound::DISTRICT, Compound::DISTRICT], Compound::extent());
        $this->assertCount(Compound::DISTRICT, Compound::ground());
        $this->assertSame(2 * Compound::DISTRICT, strlen(Compound::ground()[0]));
    }

    /**
     * The answer to "we need more room" is never no.
     *
     * However far the compound has been extended, there is always another block
     * touching it, so the Land tab always has something to offer. This is the
     * property the fixed grid could not have — it ran out — and it is the one
     * worth guarding, because running out is silent: the tab simply says every
     * block is already in and there is nothing to press.
     */
    public function test_there_is_always_more_land_to_take_in(): void
    {
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        [$cols, $rows] = Compound::extent();

        /* Far enough to be well past anything the old fixed grid allowed. */
        for ($step = 0; $step < 8; $step++) {
            $frontier = Compound::frontier();

            $this->assertNotEmpty($frontier, "the compound ran out of room after {$step} blocks");

            /* Always outwards: every block offered touches ground already held,
               so the compound stays one piece rather than a scatter of specks. */
            $next = collect($frontier)
                ->first(fn (array $block) => $block['x1'] > $cols || $block['y1'] > $rows);

            $this->assertNotNull($next, 'the frontier offered nothing that would enlarge the compound');

            CompoundDistrict::create(['dx' => $next['dx'], 'dy' => $next['dy']]);

            [$grownCols, $grownRows] = Compound::extent();

            $this->assertTrue(
                $grownCols > $cols || $grownRows > $rows,
                'taking in a block past the edge did not make the compound bigger',
            );

            [$cols, $rows] = [$grownCols, $grownRows];
        }

        $this->assertTrue($cols > 4 * Compound::DISTRICT || $rows > 4 * Compound::DISTRICT);
    }

    /** And only outwards: the compound stays one place, not two. */
    public function test_the_frontier_is_only_the_blocks_the_compound_touches(): void
    {
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        $offered = collect(Compound::frontier())
            ->map(fn (array $block) => $block['dx'].','.$block['dy'])
            ->all();

        sort($offered);

        $this->assertSame(['0,1', '1,0'], $offered);
    }

    /**
     * Two rules, and they are deliberately different.
     *
     * A person arranging their own compound may want the guardhouse across the
     * road and the flagpole in the middle of the plaza, because that is where
     * those things are — so the only ground refused to them is ground the
     * municipality has not taken in. The automatic first layout is fussier,
     * because a machine placing twenty-one offices with no opinion about any of
     * them should keep off the streets.
     */
    public function test_anything_may_stand_on_ground_that_has_been_taken_in(): void
    {
        /*
         * Three blocks in an L, so the compound is deliberately not a filled
         * rectangle. Its extent covers four blocks and one of them is still
         * country — which is the case that matters, because a compound that
         * grows outwards in whatever direction MIS chooses will very often
         * have a corner missing.
         */
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);
        CompoundDistrict::create(['dx' => 1, 'dy' => 0]);
        CompoundDistrict::create(['dx' => 0, 'dy' => 1]);

        [$cols, $rows] = Compound::extent();
        $ground = Compound::ground();

        $this->assertSame([2 * Compound::DISTRICT, 2 * Compound::DISTRICT], [$cols, $rows]);

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $inside = ! ($x >= Compound::DISTRICT && $y >= Compound::DISTRICT);

                $this->assertSame(
                    $inside,
                    Compound::isBuildable($x, $y, 1, 1),
                    "cell {$x},{$y} disagrees about whether it is in the compound",
                );

                $this->assertSame(
                    $ground[$y][$x] === 'g',
                    Compound::isOpenGround($x, $y, 1, 1),
                    "cell {$x},{$y} is not open ground the seeder would agree with",
                );
            }
        }
    }

    /** And nothing may stand on the rest of it until somebody takes it in. */
    public function test_land_starts_out_of_the_compound_and_can_be_taken_in(): void
    {
        $this->assertFalse(Compound::isBuildable(0, 0, 1, 1));

        /* A compound with nothing in it is offered its own first corner. */
        $this->assertSame([['dx' => 0, 'dy' => 0]], collect(Compound::frontier())
            ->map(fn (array $block) => ['dx' => $block['dx'], 'dy' => $block['dy']])
            ->all());

        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        $this->assertTrue(Compound::isBuildable(0, 0, 1, 1));
        $this->assertCount(2, Compound::frontier());
    }

    public function test_a_footprint_may_not_hang_off_the_edge(): void
    {
        CompoundDistrict::create(['dx' => 0, 'dy' => 0]);

        [$cols] = Compound::extent();

        $this->assertFalse(Compound::isBuildable($cols - 1, 0, 2, 2));
        $this->assertFalse(Compound::isBuildable(-1, 0, 2, 2));
    }

    /*
    |--------------------------------------------------------------------------
    | The renderer's vocabulary
    |--------------------------------------------------------------------------
    */

    /**
     * Every sprite a place names must exist in the renderer, or the building is
     * silently skipped and there is a hole in the compound. 'office' is the
     * parameterised one and is not in the scenery table, so it is excused.
     */
    public function test_every_scenery_sprite_is_one_the_renderer_draws(): void
    {
        $this->office();
        CompoundBuilding::create(['sprite' => 'flagpole', 'gx' => 8, 'gy' => 8, 'w' => 1, 'h' => 1]);

        $js = file_get_contents(resource_path('js/compound.js'));

        foreach (Compound::places() as $place) {
            if ($place['sprite'] === 'office') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^\s{4}'.preg_quote($place['sprite'], '/').'\(c, b/m',
                $js,
                'compound.js has no scenery named '.$place['sprite'],
            );
        }
    }

    /**
     * The compound must be reached by a real page load.
     *
     * Its renderer is an ES module, and a module is evaluated once per document
     * — arriving through Livewire's wire:navigate would swap the markup in and
     * never run the code that draws into it. The failure is a blank stage, which
     * looks like a rendering bug and is actually a routing one.
     */
    public function test_the_sidebar_link_to_the_compound_is_not_a_soft_navigation(): void
    {
        $this->actingAs($this->superAdmin());

        $nav = collect(Navigation::forCurrentUser())->keyBy('icon');

        $this->assertFalse($nav['compound']['navigate']);
        $this->assertTrue($nav['dashboard']['navigate'], 'ordinary Livewire screens should still soft-navigate');
    }

    /** And the rendered sidebar honours it. */
    public function test_the_rendered_sidebar_omits_wire_navigate_on_the_compound_link(): void
    {
        $html = $this->actingAs($this->superAdmin())->get('/dashboard')->assertOk()->getContent();

        $compoundUrl = route('compound');

        // The anchor for the compound, up to the end of its opening tag.
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote($compoundUrl, '/').'"[^>]*>/', $html);
        preg_match('/<a href="'.preg_quote($compoundUrl, '/').'"([^>]*)>/', $html, $m);

        $this->assertStringNotContainsString('wire:navigate', $m[1]);
        $this->assertStringContainsString('data-tour="compound"', $m[1]);
    }

    /** The page lists the same offices in plain text below the drawing. */
    public function test_it_also_lists_every_office_as_ordinary_text(): void
    {
        $this->office('MO');
        $this->office('MTO');

        $response = $this->get('/compound');

        foreach (Compound::places() as $place) {
            if ($place['kind'] === 'office') {
                $response->assertSee($place['name']);
            }
        }
    }
}
