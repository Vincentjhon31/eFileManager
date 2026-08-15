<?php

namespace Database\Seeders;

use App\Models\CompoundBuilding;
use App\Models\CompoundDistrict;
use App\Models\CompoundTile;
use App\Models\Department;
use App\Support\Compound;
use Illuminate\Database\Seeder;

/**
 * The compound, laid out for the first time.
 *
 * Somebody with settings.manage will drag these into an arrangement that
 * matches the real grounds, and that arrangement is the one that matters. This
 * only has to produce a compound that is not empty and not nonsense: every
 * office standing on grass, none on top of another, in the order they appear on
 * the organisational chart.
 *
 * Placed by a scan rather than by a hand-written table of coordinates. A table
 * would have to be corrected every time an office is added or the ground map
 * changes, and the correction is exactly the kind that gets forgotten until
 * somebody notices the Legal Office standing in the sea.
 *
 * Idempotent, like every seeder here: an office that already has a building
 * keeps the place it was dragged to.
 */
class CompoundSeeder extends Seeder
{
    /**
     * The bigger offices, and how big.
     *
     * Height is people, not importance — the council chamber is a hall and the
     * Civil Registrar is a counter, and drawing them the same size would be the
     * lie. Anything not listed gets an ordinary two-by-two.
     */
    private const SIZES = [
        'MO' => ['w' => 3, 'h' => 2, 'height' => 46],
        'SB' => ['w' => 3, 'h' => 2, 'height' => 40],
        'MHO' => ['w' => 3, 'h' => 2, 'height' => 28],
        'MEO' => ['w' => 2, 'h' => 3, 'height' => 32],
        'MTO' => ['w' => 2, 'h' => 2, 'height' => 34],
        'MVO' => ['w' => 2, 'h' => 2, 'height' => 34],
    ];

    /**
     * Wall and roof, cycled.
     *
     * Two colours per building and the renderer derives every face from them,
     * so this is the whole of what makes twenty-one buildings tellable apart at
     * a glance. Roofs are the varied half because a roof is what you see most
     * of from above.
     */
    private const COLOURS = [
        ['#ede3d2', '#2e7d7b'],
        ['#f2f0e6', '#c1462f'],
        ['#e4d9bf', '#8e5a3c'],
        ['#d9ddd6', '#56707f'],
        ['#e9e2d0', '#7a4e7e'],
        ['#f4ecda', '#e0a526'],
        ['#ede3d2', '#1f6b69'],
        ['#e9e2d0', '#5d6a73'],
        ['#f2f0e6', '#6fa84f'],
        ['#e4d9bf', '#4a3f7a'],
    ];

    /**
     * The streets the compound was laid out around, and its plaza.
     *
     * These lived in App\Support\Compound as constants until paving became
     * something somebody could do — at which point a starting arrangement is a
     * seed, not a rule. Two streets each way: the first pair is the cross every
     * building placed so far sits in a block of, the second serves the ground
     * to the south and east that nothing has been built on yet.
     */
    private const STREETS_X = [8, 20];

    private const STREETS_Y = [7, 19];

    private const PLAZA = ['x0' => 7, 'x1' => 10, 'y0' => 6, 'y1' => 9];

    /**
     * How much ground the compound starts with, in cells each way.
     *
     * A seeder's business, not the compound's. App\Support\Compound has no size
     * of its own any more — it is as big as the blocks taken into it — so the
     * starting size is whatever the first layout needs, which is four blocks
     * each way. Everything past that is country until somebody takes it in.
     */
    private const START = 4 * Compound::DISTRICT;

    public function run(): void
    {
        $this->layTheStreets();

        $taken = collect(CompoundBuilding::all());

        $this->placeScenery($taken);

        $offices = Department::query()->internal()->orderBy('sort_order')->orderBy('name')->get();

        foreach ($offices->values() as $i => $office) {
            if ($taken->firstWhere('department_id', $office->getKey())) {
                continue;
            }

            $size = self::SIZES[$office->code] ?? ['w' => 2, 'h' => 2, 'height' => 26];
            [$wall, $roof] = self::COLOURS[$i % count(self::COLOURS)];

            $spot = $this->freeSpot($taken, $size['w'], $size['h']);

            if (! $spot) {
                $this->command?->warn("No room left in the compound for {$office->code}.");

                continue;
            }

            $taken->push(CompoundBuilding::create([
                'department_id' => $office->getKey(),
                'sprite' => 'office',
                'gx' => $spot[0],
                'gy' => $spot[1],
                'w' => $size['w'],
                'h' => $size['h'],
                'height' => $size['height'],
                'wall' => $wall,
                'roof' => $roof,
            ]));
        }

        $this->openTheLandUnder($taken);
    }

    /**
     * Take in every block that has something standing on it.
     *
     * Only those: a compound that started out as a wide empty field with four
     * buildings in one corner would look like a mistake, and the ground beyond
     * what is in use is country until somebody in MIS takes it in — which is
     * the point of dividing it up at all, and is now something they can keep
     * doing outwards for as long as the municipality keeps growing.
     *
     * Ordered after the placing above because the placing is what decides which
     * blocks are in use — and idempotent, so a re-run never takes back land or
     * hands over more of it.
     */
    private function openTheLandUnder($taken): void
    {
        $blocks = [];

        foreach ($taken as $building) {
            for ($x = $building->gx; $x < $building->gx + $building->w; $x++) {
                for ($y = $building->gy; $y < $building->gy + $building->h; $y++) {
                    $blocks[intdiv($x, Compound::DISTRICT).','.intdiv($y, Compound::DISTRICT)] = [
                        intdiv($x, Compound::DISTRICT),
                        intdiv($y, Compound::DISTRICT),
                    ];
                }
            }
        }

        foreach ($blocks as [$dx, $dy]) {
            CompoundDistrict::firstOrCreate(['dx' => $dx, 'dy' => $dy]);
        }
    }

    /**
     * Pave the original streets and plaza, once.
     *
     * firstOrCreate rather than updateOrCreate on purpose: somebody who has dug
     * up a stretch of the north street and put grass back keeps their grass.
     * Re-running a seeder should never undo a decision.
     */
    private function layTheStreets(): void
    {
        if (CompoundTile::query()->exists()) {
            return;
        }

        for ($y = 0; $y < self::START; $y++) {
            for ($x = 0; $x < self::START; $x++) {
                $kind = match (true) {
                    $x >= self::PLAZA['x0'] && $x <= self::PLAZA['x1']
                        && $y >= self::PLAZA['y0'] && $y <= self::PLAZA['y1'] => 'p',
                    in_array($x, self::STREETS_X, true), in_array($y, self::STREETS_Y, true) => 'r',
                    default => null,
                };

                if ($kind) {
                    CompoundTile::create(['x' => $x, 'y' => $y, 'kind' => $kind]);
                }
            }
        }
    }

    /**
     * The gate, the flagpole and the two bits of furniture every compound has.
     *
     * Fixed positions, because each one means something about where it is: the
     * gate is on the road, and the flagpole is in the plaza because that is
     * where the ceremony happens. These are the only coordinates written down
     * anywhere, and they are written down for that reason.
     */
    private function placeScenery($taken): void
    {
        $scenery = [
            ['sprite' => 'gate', 'gx' => 7, 'gy' => 15, 'w' => 3, 'h' => 1, 'height' => 24],
            ['sprite' => 'flagpole', 'gx' => 8, 'gy' => 8, 'w' => 1, 'h' => 1, 'height' => 54],
            ['sprite' => 'shed', 'gx' => 10, 'gy' => 10, 'w' => 2, 'h' => 1, 'height' => 16],
            ['sprite' => 'jeepney', 'gx' => 5, 'gy' => 13, 'w' => 2, 'h' => 1, 'height' => 18],
        ];

        foreach ($scenery as $piece) {
            if ($taken->firstWhere(fn (CompoundBuilding $b) => $b->department_id === null && $b->sprite === $piece['sprite'])) {
                continue;
            }

            $taken->push(CompoundBuilding::create($piece + [
                'wall' => '#ede3d2',
                'roof' => '#c1462f',
            ]));
        }
    }

    /**
     * The first cell a footprint of this size fits in, reading order.
     *
     * A one-cell gap is kept between buildings. Without it two neighbours share
     * a wall, and in isometric projection two buildings that touch read as one
     * larger building with a strange roof.
     *
     * @return array{0: int, 1: int}|null
     */
    private function freeSpot($taken, int $w, int $h): ?array
    {
        for ($gy = 0; $gy < self::START; $gy++) {
            for ($gx = 0; $gx < self::START; $gx++) {
                if (! Compound::isOpenGround($gx, $gy, $w, $h, [self::START, self::START])) {
                    continue;
                }

                $candidate = new CompoundBuilding([
                    'gx' => $gx - 1, 'gy' => $gy - 1, 'w' => $w + 2, 'h' => $h + 2,
                ]);

                $clash = $taken->contains(fn (CompoundBuilding $b) => $candidate->overlaps($b));

                if (! $clash) {
                    return [$gx, $gy];
                }
            }
        }

        return null;
    }
}
