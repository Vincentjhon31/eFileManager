<?php

namespace Database\Seeders;

use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Department;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Seeder;

class BuildingSeeder extends Seeder
{
    /**
     * The municipal hall, its second floor, and the rooms on it.
     *
     * Traced from docs/floor-plans/Mayor's_Office.png. The svg_shape_id of each
     * room matches an element id in resources/svg/floors/hall-second-floor.svg —
     * that one string is the whole coupling between the drawing and the data.
     *
     * ────────────────────────────────────────────────────────────────────────
     *  NOTE FOR VERIFICATION — who works where
     *
     *  Four rooms are assigned to offices with confidence: the Mayor's Office,
     *  the SB Session Hall, the IT Room and the Conference Hall.
     *
     *  Two are deliberately left UNASSIGNED, and their emptiness is a finding
     *  rather than an oversight:
     *
     *    OFFICE OF THE MUNICIPAL ADMINISTRATOR
     *    OFFICE OF THE SECRETARY & ADMINISTRATOR
     *
     *  Both are plainly labelled on the plan and neither corresponds to any
     *  code in DepartmentSeeder — which is direct evidence that the seeded
     *  roster there is incomplete. They will show on the map as "no office
     *  assigned" until somebody with authority maps them on the Rooms screen.
     *  Guessing a code here would put a wrong office on a wall display and
     *  route documents into it.
     * ────────────────────────────────────────────────────────────────────────
     *
     * Idempotent: safe to re-run after the drawing is revised or an office is
     * confirmed.
     */
    public function run(): void
    {
        $hall = Building::updateOrCreate(
            ['code' => 'HALL'],
            [
                'name' => 'Municipal Hall',
                'description' => 'The main municipal building on the town plaza.',
                'sort_order' => 10,
            ],
        );

        $second = Floor::updateOrCreate(
            ['building_id' => $hall->id, 'level' => 2],
            [
                'name' => 'Second floor',
                'slug' => 'hall-second-floor',
                'svg_path' => 'floors/hall-second-floor.svg',
                'has_map' => true,
                'sort_order' => 20,
            ],
        );

        // The ground floor exists so the building reads as a building rather
        // than a single orphaned plan. It has no drawing yet and says so.
        Floor::updateOrCreate(
            ['building_id' => $hall->id, 'level' => 1],
            [
                'name' => 'Ground floor',
                'slug' => 'hall-ground-floor',
                'svg_path' => null,
                'has_map' => false,
                'sort_order' => 10,
            ],
        );

        $offices = Department::pluck('id', 'code');

        // shape id, name, type, office code (null = unassigned), badge x%, y%
        $rooms = [
            ['room-mayors-office', "Mayor's Office", RoomType::Office, 'MO', 78.25, 52.41],
            ['room-conference-hall', 'Conference Hall', RoomType::Meeting, 'MO', 96.25, 41.79],
            ['room-sb-session-hall', 'SB Session Hall', RoomType::Meeting, 'SB', 36.38, 15.00],
            ['room-it-room', 'IT Room', RoomType::Office, 'MIS', 17.19, 2.86],

            ['room-municipal-administrator', 'Office of the Municipal Administrator', RoomType::Office, null, 42.00, 41.25],
            ['room-secretary-administrator', 'Office of the Secretary and Administrator', RoomType::Office, null, 43.88, 72.50],

            ['room-front-desk', 'Front Desk', RoomType::Public, null, 53.88, 52.41],
            ['room-public-assistance', 'Public Assistance Desk', RoomType::Public, null, 61.75, 22.50],
            ['room-visitors-waiting', "Visitors' Waiting Area", RoomType::Public, null, 76.38, 41.07],

            ['room-lobby', 'Main entrance lobby', RoomType::Circulation, null, 48.63, 41.25],
            ['room-east-corridor', 'East corridor', RoomType::Circulation, null, 79.38, 41.25],
            ['room-entrance-stairs', 'Entrance and exit stairs', RoomType::Circulation, null, 61.38, 2.86],
            ['room-exit-stairs', 'Exit stairs', RoomType::Circulation, null, 14.81, 44.82],
            ['room-elevator', 'Elevator', RoomType::Circulation, null, 35.00, 2.23],
            ['room-balcony', 'Balcony', RoomType::Circulation, null, 71.44, 24.29],

            ['room-female-cr', 'Female comfort room', RoomType::Utility, null, 6.88, 41.70],
            ['room-male-cr', 'Male comfort room', RoomType::Utility, null, 10.00, 55.98],
            ['room-genderized-cr', 'Genderized comfort room', RoomType::Utility, null, 10.31, 63.57],
            ['room-pantry', 'Pantry', RoomType::Utility, null, 14.56, 77.41],
            ['room-mayors-pantry', "Mayor's Pantry", RoomType::Utility, null, 53.88, 72.50],
            ['room-mayors-cr', "Mayor's comfort room", RoomType::Utility, null, 53.88, 80.09],
        ];

        foreach ($rooms as $index => [$shapeId, $name, $type, $code, $x, $y]) {
            Room::updateOrCreate(
                ['floor_id' => $second->id, 'svg_shape_id' => $shapeId],
                [
                    'name' => $name,
                    'type' => $type,
                    // Only ever set from a code that actually exists, so a
                    // renamed office leaves the room unassigned and visible as
                    // such rather than silently pointing at nothing.
                    'department_id' => $code ? ($offices[$code] ?? null) : null,
                    'centroid_x' => $x,
                    'centroid_y' => $y,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }
}
