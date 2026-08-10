<?php

namespace Tests\Feature\Building;

use App\Enums\ActionRequested;
use App\Enums\DoorState;
use App\Enums\ReceiptMethod;
use App\Enums\Role as RoleEnum;
use App\Enums\RoomType;
use App\Livewire\Building\FloorMap;
use App\Models\Building;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use App\Services\DocumentRoutingService;
use App\Support\DoorStates;
use Database\Seeders\BuildingSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The building map.
 *
 * The thing under test is not the picture. It is the claim the picture makes:
 * that a door's colour is an honest summary of what is waiting behind it, and
 * that clicking one gets you no further into another office's papers than any
 * other screen would.
 */
class FloorMapTest extends TestCase
{
    use RefreshDatabase;

    private DocumentRoutingService $routing;

    private Department $mayor;

    private Department $budget;

    private Floor $floor;

    private Room $mayorsOffice;

    private Room $budgetOffice;

    private DocumentType $memo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->routing = app(DocumentRoutingService::class);

        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->budget = Department::factory()->onboarded()->create(['code' => 'MBO', 'short_name' => 'Budget']);
        $this->memo = DocumentType::factory()->create();

        $hall = Building::factory()->create(['code' => 'HALL', 'name' => 'Municipal Hall']);
        $this->floor = Floor::factory()->drawn()->create([
            'building_id' => $hall->id,
            'level' => 2,
            'name' => 'Second floor',
            'slug' => 'hall-second-floor',
        ]);

        $this->mayorsOffice = Room::factory()->onFloor($this->floor)->forOffice($this->mayor)->create([
            'name' => "Mayor's Office", 'svg_shape_id' => 'room-mayors-office',
        ]);
        $this->budgetOffice = Room::factory()->onFloor($this->floor)->forOffice($this->budget)->create([
            'name' => 'Budget Office', 'svg_shape_id' => 'room-budget',
        ]);
    }

    private function staff(Department $office, RoleEnum $role = RoleEnum::ReceivingClerk): User
    {
        $user = User::factory()->inDepartment($office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function registerFor(Department $office): Document
    {
        return $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Budget hearing schedule',
            'origin_department_id' => $office->id,
        ], $this->staff($office));
    }

    private function statesFor(User $viewer): array
    {
        return DoorStates::for($this->floor->rooms()->get(), $viewer);
    }

    /*
    |--------------------------------------------------------------------------
    | Door states
    |--------------------------------------------------------------------------
    */

    public function test_a_quiet_office_shows_a_clear_door(): void
    {
        $states = $this->statesFor($this->staff($this->mayor));

        $this->assertSame(DoorState::Idle, $states[$this->mayorsOffice->id]['state']);
        $this->assertSame(0, $states[$this->mayorsOffice->id]['waiting']);
    }

    public function test_a_room_with_no_office_mapped_to_it_shows_as_vacant(): void
    {
        $cr = Room::factory()->onFloor($this->floor)->type(RoomType::Utility)->create(['name' => 'Male CR']);

        $states = $this->statesFor($this->staff($this->mayor));

        $this->assertSame(DoorState::Vacant, $states[$cr->id]['state']);
        $this->assertFalse($states[$cr->id]['canOpen']);
    }

    public function test_a_transmittal_waiting_to_be_received_turns_the_door_amber(): void
    {
        $clerk = $this->staff($this->mayor);
        $document = $this->registerFor($this->mayor);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $states = $this->statesFor($clerk);

        $this->assertSame(DoorState::Pending, $states[$this->budgetOffice->id]['state']);
        $this->assertSame(1, $states[$this->budgetOffice->id]['incoming']);
        $this->assertSame(0, $states[$this->budgetOffice->id]['onDesk']);

        // And it is not on the sender's door — the paper has left them.
        $this->assertSame(DoorState::Idle, $states[$this->mayorsOffice->id]['state']);
    }

    public function test_a_document_sitting_on_a_desk_turns_the_door_amber(): void
    {
        $clerk = $this->staff($this->mayor);
        $document = $this->registerFor($this->mayor);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        // Budget sign for it themselves — they are onboarded here.
        $this->routing->receive($document, $this->staff($this->budget), ReceiptMethod::System);

        $states = $this->statesFor($clerk);

        $this->assertSame(DoorState::Pending, $states[$this->budgetOffice->id]['state']);
        $this->assertSame(1, $states[$this->budgetOffice->id]['onDesk']);
        $this->assertSame(0, $states[$this->budgetOffice->id]['incoming']);
    }

    /** One late paper among forty on time still turns the door red. */
    public function test_one_overdue_document_beats_everything_else(): void
    {
        $clerk = $this->staff($this->mayor);

        Document::factory()->count(3)->forOffice($this->budget)
            ->heldBy($this->budget)->create(['document_type_id' => $this->memo->id]);

        Document::factory()->forOffice($this->budget)->heldBy($this->budget)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->subDays(2),
        ]);

        $states = $this->statesFor($clerk);

        $this->assertSame(DoorState::Overdue, $states[$this->budgetOffice->id]['state']);
        $this->assertSame(4, $states[$this->budgetOffice->id]['waiting']);
        $this->assertSame(1, $states[$this->budgetOffice->id]['overdue']);
        $this->assertTrue($states[$this->budgetOffice->id]['state']->shouldPulse());
    }

    /**
     * The judgement this whole screen rests on: a count is what a stack of
     * folders on a desk tells anyone walking past, so every employee sees every
     * door. Reading the papers is a separate question.
     */
    public function test_every_employee_sees_every_offices_counts(): void
    {
        $clerk = $this->staff($this->mayor);
        $document = $this->registerFor($this->mayor);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        // Somebody with no connection to either office.
        $outsider = $this->staff(Department::factory()->onboarded()->create(['code' => 'HRMO']));

        $states = $this->statesFor($outsider);

        $this->assertSame(DoorState::Pending, $states[$this->budgetOffice->id]['state']);
        $this->assertSame(1, $states[$this->budgetOffice->id]['waiting']);
    }

    /**
     * A session hall is where an office meets, not where its papers sit.
     * Tinting it by their caseload would be a small lie about the building, so
     * the door stays neutral while the panel behind it still tells the truth.
     */
    public function test_a_meeting_room_reports_its_offices_counts_without_painting_the_door(): void
    {
        $hall = Room::factory()->onFloor($this->floor)->forOffice($this->budget)
            ->type(RoomType::Meeting)->create(['name' => 'SB Session Hall']);

        Document::factory()->forOffice($this->budget)->heldBy($this->budget)
            ->create(['document_type_id' => $this->memo->id]);

        $states = $this->statesFor($this->staff($this->mayor));

        $this->assertFalse($states[$hall->id]['showsState']);
        $this->assertSame(1, $states[$hall->id]['onDesk'], 'The count is still known — it is just not painted on.');

        // The office it stands for does paint its door.
        $this->assertTrue($states[$this->budgetOffice->id]['showsState']);
    }

    public function test_a_door_with_nothing_to_say_gets_no_style_rule_at_all(): void
    {
        Room::factory()->onFloor($this->floor)->type(RoomType::Utility)
            ->create(['name' => 'Pantry', 'svg_shape_id' => 'room-pantry']);

        $html = Livewire::actingAs($this->staff($this->mayor))->test(FloorMap::class)->html();

        // The drawing's own neutral fill stands rather than being overpainted.
        $this->assertStringContainsString('#room-mayors-office{fill:', $html);
        $this->assertStringNotContainsString('#room-pantry{fill:', $html);
    }

    public function test_only_your_own_office_is_yours_to_open(): void
    {
        $states = $this->statesFor($this->staff($this->mayor));

        $this->assertTrue($states[$this->mayorsOffice->id]['canOpen']);
        $this->assertFalse($states[$this->budgetOffice->id]['canOpen']);
    }

    public function test_a_system_administrator_can_open_every_room(): void
    {
        $states = $this->statesFor($this->staff($this->mayor, RoleEnum::SuperAdmin));

        $this->assertTrue($states[$this->mayorsOffice->id]['canOpen']);
        $this->assertTrue($states[$this->budgetOffice->id]['canOpen']);
    }

    /**
     * This screen sits open on a wall display refreshing every thirty seconds.
     * Counting each room separately would be a query per room, all day.
     */
    public function test_the_whole_floor_is_counted_in_a_fixed_number_of_queries(): void
    {
        Room::factory()->count(15)->onFloor($this->floor)
            ->forOffice(Department::factory()->onboarded()->create())->create();

        $viewer = $this->staff($this->mayor);
        $rooms = $this->floor->rooms()->get();

        $this->assertGreaterThan(15, $rooms->count());

        // Warm the permission cache first. Spatie loads roles and permissions
        // on the first can() of a request; counting that here would measure the
        // authorisation layer rather than this one.
        DoorStates::for($rooms->take(1), $viewer);

        DB::enableQueryLog();
        DoorStates::for($rooms, $viewer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            2,
            count($queries),
            'Door states must be counted in a fixed number of queries, not one per room.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The screen
    |--------------------------------------------------------------------------
    */

    public function test_the_map_draws_the_floor_and_lists_its_rooms(): void
    {
        $this->actingAs($this->staff($this->mayor))
            ->get(route('building'))
            ->assertOk()
            ->assertSee("Mayor's Office")
            ->assertSee('Budget Office')
            // The plan is inlined, not linked, so it can be styled by id.
            ->assertSee('room-mayors-office', false);
    }

    public function test_a_floor_with_no_drawing_still_lists_its_rooms(): void
    {
        $undrawn = Floor::factory()->create([
            'building_id' => $this->floor->building_id,
            'level' => 1,
            'name' => 'Ground floor',
            'slug' => 'hall-ground-floor',
        ]);
        Room::factory()->onFloor($undrawn)->undrawn()->create(['name' => 'Treasury Counter']);

        Livewire::actingAs($this->staff($this->mayor))
            ->test(FloorMap::class)
            ->call('showFloor', 'hall-ground-floor')
            ->assertSee('no plan drawn')
            ->assertSee('Treasury Counter');
    }

    public function test_opening_a_room_shows_that_offices_counts(): void
    {
        $clerk = $this->staff($this->mayor);
        $document = $this->registerFor($this->mayor);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(FloorMap::class)
            ->call('selectRoom', $this->budgetOffice->id)
            ->assertSee('Budget')
            ->assertSee('To receive');
    }

    /**
     * The door tells an outsider how much is waiting. It does not tell them
     * what — the drill-down runs through the same visibility scope as every
     * other listing in the system.
     */
    public function test_opening_another_offices_room_shows_counts_but_no_documents(): void
    {
        $outsider = $this->staff(Department::factory()->onboarded()->create(['code' => 'HRMO']));

        Document::factory()->forOffice($this->budget)->heldBy($this->budget)->create([
            'document_type_id' => $this->memo->id,
            'subject' => 'Confidential budget realignment',
        ]);

        Livewire::actingAs($outsider)
            ->test(FloorMap::class)
            ->call('selectRoom', $this->budgetOffice->id)
            ->assertSee('On their desk')
            ->assertDontSee('Confidential budget realignment')
            ->assertSee('is a different matter');
    }

    public function test_your_own_room_lists_what_is_on_the_desk(): void
    {
        $clerk = $this->staff($this->mayor);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor)->create([
            'document_type_id' => $this->memo->id,
            'subject' => 'Purchase request for office supplies',
        ]);

        Livewire::actingAs($clerk)
            ->test(FloorMap::class)
            ->call('selectRoom', $this->mayorsOffice->id)
            ->assertSee('Purchase request for office supplies');
    }

    public function test_an_unmapped_room_says_so_rather_than_guessing(): void
    {
        $room = Room::factory()->onFloor($this->floor)->create(['name' => 'Office of the Municipal Administrator']);

        Livewire::actingAs($this->staff($this->mayor))
            ->test(FloorMap::class)
            ->call('selectRoom', $room->id)
            ->assertSee('no office has been mapped to it');
    }

    /*
    |--------------------------------------------------------------------------
    | The drawing itself
    |--------------------------------------------------------------------------
    */

    public function test_every_shape_in_the_drawing_has_a_room_and_every_room_a_shape(): void
    {
        // Clear this class's fixtures first: what is under test is whether the
        // seeder and the drawing agree, not whether a test room happens to be
        // on the same floor.
        Room::query()->delete();

        $this->seed(DepartmentSeeder::class);
        $this->seed(BuildingSeeder::class);

        $floor = Floor::where('slug', 'hall-second-floor')->firstOrFail();
        $svg = $floor->svg();

        $this->assertNotNull($svg, 'The second-floor drawing must be readable.');

        preg_match_all('/id="(room-[a-z0-9-]+)"/', $svg, $matches);
        $inDrawing = collect($matches[1])->unique()->sort()->values();
        $inDatabase = $floor->rooms()->whereNotNull('svg_shape_id')->pluck('svg_shape_id')->sort()->values();

        // A shape with no room can never be coloured; a room with no shape can
        // never be found on the plan. Either is a silent hole in the map.
        $this->assertSame(
            $inDrawing->all(),
            $inDatabase->all(),
            'The drawing and the seeder disagree about which rooms exist.',
        );
    }

    /**
     * Browsers forgive malformed SVG; nothing else does.
     *
     * An invalid comment or an unclosed tag would still render on screen and
     * then break the first tool anybody points at the file — a converter, a
     * minifier, a drawing package. Cheap to check, and the failure it prevents
     * is one that only appears in somebody else's hands.
     */
    public function test_the_drawing_is_well_formed_xml(): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument;
        $loaded = $document->loadXML(
            file_get_contents(resource_path('svg/floors/hall-second-floor.svg'))
        );

        $errors = collect(libxml_get_errors())->map(
            fn ($e) => trim($e->message).' (line '.$e->line.')'
        )->all();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'The drawing is not well-formed XML: '.implode('; ', $errors));
        $this->assertSame([], $errors);
    }

    /**
     * This file is inlined verbatim into an authenticated page. Anything it
     * could fetch, it would fetch as the signed-in user.
     */
    public function test_the_drawing_contains_no_script_or_external_reference(): void
    {
        $svg = (new Floor(['svg_path' => 'floors/hall-second-floor.svg']))->svg();

        $this->assertNotNull($svg);

        // Strip XML comments before scanning. A comment cannot fetch anything,
        // and the file's own instructions name the tags it forbids.
        $markup = preg_replace('/<!--.*?-->/s', '', $svg);

        foreach (['<script', 'href="http', 'src=', '<foreignObject', 'javascript:', '<image', 'onload'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $markup);
        }
    }

    /** Room fills must be attributes, or the generated stylesheet cannot win. */
    public function test_room_shapes_carry_no_inline_styles(): void
    {
        $svg = (new Floor(['svg_path' => 'floors/hall-second-floor.svg']))->svg();

        preg_match_all('/<path id="room-[^>]*>/', $svg, $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertStringNotContainsString(
                'style=',
                $tag,
                'An inline style on a room shape would override the door state and freeze its colour.',
            );
        }
    }

    public function test_a_drawing_path_cannot_climb_out_of_the_assets_directory(): void
    {
        $floor = new Floor(['svg_path' => '../../../.env']);

        $this->assertNull($floor->svg());
    }
}
