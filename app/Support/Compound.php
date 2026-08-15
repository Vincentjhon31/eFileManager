<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Announcement;
use App\Models\CompoundBuilding;
use App\Models\CompoundDistrict;
use App\Models\CompoundTile;
use App\Models\Department;
use App\Models\LandmarkPhoto;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The compound, as a place you can walk through.
 *
 * Same split as App\Support\World and for the same reason: this class decides
 * what the buildings are, what they say and where their doors lead, and
 * resources/js/compound.js draws whatever it is handed. Two differences.
 *
 * **It is a map, not a row.** The town is laid out by walking an array and
 * asking each sprite how wide it is, so it has no coordinates anywhere. The
 * compound is drawn in isometric projection on a grid, and where a building
 * stands is a fact somebody decided by dragging it — so it lives in
 * compound_buildings and comes back out of the database.
 *
 * **The buildings are offices.** They used to be the sidebar's screens, one
 * building per destination, which made the compound a second shelf for the same
 * links. It is now the municipality: the Mayor's Office is a building because
 * the Mayor's Office is a building. Navigation still belongs to the sidebar,
 * and appears in the panel of the office you actually work in.
 *
 * Open to guests. An office directory with a map is a genuinely useful public
 * thing, and nothing behind a door has changed — every screen keeps its own
 * middleware and its own policy, and a guest is offered sign-in rather than a
 * link they would be turned away from.
 */
class Compound
{
    /**
     * How many cells to a block of land.
     *
     * Seven — small enough that taking one in is a decision somebody makes on
     * purpose, large enough that it is worth making. A block is about the
     * ground four ordinary offices stand on.
     */
    public const DISTRICT = 7;

    /**
     * How far the compound may ever grow, in blocks each way.
     *
     * Not the size of the compound: the size of the paper it is drawn on. There
     * used to be a fixed twenty-eight-cell grid, and once the last of its
     * sixteen blocks had been taken in the compound simply could not get any
     * bigger — which is the wrong answer for a municipality that gets a new
     * office every few years.
     *
     * So the compound is now as big as the ground taken into it and no bigger,
     * and this is only a backstop: eighty-four cells each way, far past
     * anything a town hall will need, there so that a coordinate arriving over
     * the wire has *some* bound to be checked against.
     */
    public const MAX_DISTRICTS = 12;

    /** The same, in cells. Nothing may be placed outside it. */
    public const MAX = self::MAX_DISTRICTS * self::DISTRICT;

    /**
     * Everything the renderer needs, in one object.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $user = auth()->user();
        $mayArrange = (bool) $user?->can(Permission::SettingsManage->value);

        [$cols, $rows] = self::extent();

        return [
            'cols' => $cols,
            'rows' => $rows,
            'district' => self::DISTRICT,
            'ground' => self::ground(),
            'unlocked' => array_values(self::unlockedDistricts()),
            'places' => self::places($user),
            'you' => $user?->department?->code,
            'canArrange' => $mayArrange,

            /*
             * Only somebody who can arrange the compound is shown the land they
             * have not taken in yet — to everybody else it is simply country,
             * and a padlock over a field they cannot do anything about is a
             * locked door where there was no door.
             */
            'land' => $mayArrange ? self::frontier() : [],
            'giveBack' => $mayArrange ? self::removable() : [],
            'templates' => $mayArrange ? self::templates() : [],
            'categories' => $mayArrange ? self::categories() : [],
            'brushes' => $mayArrange ? self::brushes() : [],
            'palette' => $mayArrange ? self::palette() : [],
            'vacant' => $mayArrange ? self::officesWithoutABuilding() : [],
            'canCreateOffices' => (bool) $user?->can(Permission::DepartmentsManage->value),

            'intro' => self::intro($user),
            'tips' => self::tips(),
            'title' => World::shortName(),
            'subtitle' => 'The Compound',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The land
    |--------------------------------------------------------------------------
    */

    /**
     * Every block taken in so far, keyed "dx,dy".
     *
     * @return array<string, string>
     */
    public static function unlockedDistricts(): array
    {
        return CompoundDistrict::query()
            ->get(['dx', 'dy'])
            ->mapWithKeys(fn (CompoundDistrict $d) => [$d->key() => $d->key()])
            ->all();
    }

    /**
     * How big the compound currently is, in cells.
     *
     * Derived from the ground taken in rather than declared: the compound *is*
     * its blocks, and its edge is wherever the last one stops. Taking in a
     * block past the current edge is what makes it bigger, which is the whole
     * point of taking one in.
     *
     * @return array{0: int, 1: int}
     */
    public static function extent(?array $unlocked = null): array
    {
        $unlocked ??= self::unlockedDistricts();

        $cols = 0;
        $rows = 0;

        foreach ($unlocked as $key) {
            [$dx, $dy] = array_map('intval', explode(',', $key));

            $cols = max($cols, ($dx + 1) * self::DISTRICT);
            $rows = max($rows, ($dy + 1) * self::DISTRICT);
        }

        /* A compound with no ground at all would be a blank screen. One block
           is where every compound starts. */
        return [max($cols, self::DISTRICT), max($rows, self::DISTRICT)];
    }

    /**
     * The blocks that could be taken in next.
     *
     * Only the ones touching ground already held — you extend a compound, you
     * do not annex a field two kilometres away and leave the gap in between.
     * That rule is also what makes this list finite and short on a plane with
     * no edges: the frontier is always the handful of blocks around what is
     * there, and there is always one, so the compound can always grow.
     *
     * @return array<int, array<string, int>>
     */
    public static function frontier(): array
    {
        $unlocked = self::unlockedDistricts();

        /* Nothing held yet: the compound starts at its own corner. */
        if ($unlocked === []) {
            return [self::block(0, 0)];
        }

        $edge = [];

        foreach ($unlocked as $key) {
            [$dx, $dy] = array_map('intval', explode(',', $key));

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$ox, $oy]) {
                $nx = $dx + $ox;
                $ny = $dy + $oy;

                if ($nx < 0 || $ny < 0 || $nx >= self::MAX_DISTRICTS || $ny >= self::MAX_DISTRICTS) {
                    continue;
                }

                if (isset($unlocked[$nx.','.$ny]) || isset($edge[$nx.','.$ny])) {
                    continue;
                }

                $edge[$nx.','.$ny] = self::block($nx, $ny);
            }
        }

        ksort($edge);

        return array_values($edge);
    }

    /**
     * The blocks that could be given back.
     *
     * Taking land in used to be one-way, on the reasoning that a block with the
     * Health Office standing on it must never be handed back and a rule about
     * which ones may be is a rule that would be got wrong once and lose a
     * building. The rule turns out to be short enough to be obviously right, so
     * here it is — three conditions, all of them about not breaking something
     * that already exists:
     *
     *   Nothing may be standing on it. A building on ground the municipality
     *   does not hold is a building nobody can move, because every rule about
     *   moving one asks whether the ground is ours.
     *
     *   What is left has to stay in one piece. Removing a block from the middle
     *   would leave the compound as two patches with a field between them, and
     *   the far patch could then never be walked to or extended.
     *
     *   And never the last one. A compound with no ground is a blank screen
     *   with no way back, because taking land in starts from land held.
     *
     * @return array<int, array<string, int>>
     */
    public static function removable(): array
    {
        $unlocked = self::unlockedDistricts();

        if (count($unlocked) <= 1) {
            return [];
        }

        $occupied = self::occupiedDistricts();
        $out = [];

        foreach ($unlocked as $key) {
            if (isset($occupied[$key]) || ! self::staysWholeWithout($key, $unlocked)) {
                continue;
            }

            [$dx, $dy] = array_map('intval', explode(',', $key));

            $out[] = self::block($dx, $dy);
        }

        return $out;
    }

    /** Whether this one block may be given back. */
    public static function isRemovable(int $dx, int $dy): bool
    {
        return collect(self::removable())
            ->contains(fn (array $block) => $block['dx'] === $dx && $block['dy'] === $dy);
    }

    /**
     * Every block with something standing on it, keyed "dx,dy".
     *
     * By cell rather than by corner: a three-wide building placed at the edge of
     * one block has its far end in the next one, and giving that one back would
     * strand half a building.
     *
     * @return array<string, true>
     */
    private static function occupiedDistricts(): array
    {
        $out = [];

        foreach (CompoundBuilding::query()->get(['gx', 'gy', 'w', 'h']) as $building) {
            for ($x = $building->gx; $x < $building->gx + $building->w; $x++) {
                for ($y = $building->gy; $y < $building->gy + $building->h; $y++) {
                    $out[intdiv($x, self::DISTRICT).','.intdiv($y, self::DISTRICT)] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Whether the compound would still be one piece without this block.
     *
     * A flood fill from any block that is left: if it reaches all of them, the
     * compound is connected, and if it does not, the block being removed was
     * the only way across.
     *
     * @param  array<string, string>  $unlocked
     */
    private static function staysWholeWithout(string $key, array $unlocked): bool
    {
        $rest = $unlocked;
        unset($rest[$key]);

        if ($rest === []) {
            return false;
        }

        $start = array_key_first($rest);
        $seen = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            [$dx, $dy] = array_map('intval', explode(',', array_pop($queue)));

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$ox, $oy]) {
                $next = ($dx + $ox).','.($dy + $oy);

                if (! isset($rest[$next]) || isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $queue[] = $next;
            }
        }

        return count($seen) === count($rest);
    }

    /** One block, as the renderer wants it: a coordinate and its cell range. */
    private static function block(int $dx, int $dy): array
    {
        return [
            'dx' => $dx,
            'dy' => $dy,
            'x0' => $dx * self::DISTRICT,
            'y0' => $dy * self::DISTRICT,
            'x1' => ($dx + 1) * self::DISTRICT,
            'y1' => ($dy + 1) * self::DISTRICT,
        ];
    }

    /** Whether one cell is on ground the municipality has taken in. */
    public static function isUnlocked(int $x, int $y, ?array $unlocked = null): bool
    {
        $unlocked ??= self::unlockedDistricts();

        return isset($unlocked[intdiv($x, self::DISTRICT).','.intdiv($y, self::DISTRICT)]);
    }

    /*
    |--------------------------------------------------------------------------
    | The ground
    |--------------------------------------------------------------------------
    */

    /**
     * What may be laid on the ground.
     *
     * Grass is the absence of anything, which is why it is not in here — you
     * clear a cell rather than paving it with grass. See App\Models\CompoundTile.
     */
    public const GROUNDS = ['r', 'p'];

    /**
     * What kind of ground is under every cell, one string per row.
     *
     * In PHP rather than as a function in the renderer because two things need
     * to agree about it: the drawing, and where the automatic first layout is
     * allowed to put an office. A pure function in JavaScript would have to be
     * written twice, and the second copy is the one that goes out of date.
     *
     *   g grass — open ground, and what the seeder places on
     *   r path  — the streets, and anything paved since
     *   p paving — the ceremonial square, and anything paved since
     *
     * The streets and the plaza were a pair of constants here until somebody
     * wanted to lay a path of their own. Paving is a thing you do to a place,
     * so it comes out of compound_tiles now — and the constants moved into the
     * seeder, which is where a starting arrangement belongs.
     *
     * As big as the compound is, which is as big as the ground taken into it.
     * The seeder is the one caller that passes its own extent, because it is
     * laying out the compound before there is any compound to measure.
     *
     * @param  array{0: int, 1: int}|null  $extent
     * @return array<int, string>
     */
    public static function ground(?array $extent = null): array
    {
        $laid = CompoundTile::query()
            ->get(['x', 'y', 'kind'])
            ->mapWithKeys(fn (CompoundTile $tile) => [$tile->x.','.$tile->y => $tile->kind])
            ->all();

        [$cols, $rows] = $extent ?? self::extent();
        $out = [];

        for ($y = 0; $y < $rows; $y++) {
            $row = '';

            for ($x = 0; $x < $cols; $x++) {
                $row .= $laid[$x.','.$y] ?? 'g';
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Whether a footprint may stand here at all.
     *
     * Inside the grid, and on land the municipality has taken in. Deliberately
     * no stricter than that: somebody arranging their own compound may well
     * want the guardhouse across the road and the flagpole in the middle of the
     * plaza, because that is where those things are. It is their compound — but
     * only as far as it goes.
     *
     * The automatic first layout is fussier — see isOpenGround() — because a
     * machine placing twenty-one offices with no opinion about any of them
     * should keep off the streets. It also runs before any land has been taken
     * in, which is why it does not ask about that.
     */
    public static function isBuildable(int $gx, int $gy, int $w, int $h): bool
    {
        $unlocked = self::unlockedDistricts();

        return self::footprintIs(
            $gx,
            $gy,
            $w,
            $h,
            fn (string $tile, int $x, int $y) => self::isUnlocked($x, $y, $unlocked),
        );
    }

    /**
     * Grass, and nothing else. What the seeder places on.
     *
     * Takes the extent it is working within, because its one caller runs before
     * any land has been taken in — at which point the compound measures itself
     * as a single block, and a seeder asked to lay out twenty-one offices
     * inside seven cells would give up after the third.
     *
     * @param  array{0: int, 1: int}|null  $extent
     */
    public static function isOpenGround(int $gx, int $gy, int $w, int $h, ?array $extent = null): bool
    {
        return self::footprintIs($gx, $gy, $w, $h, fn (string $tile) => $tile === 'g', $extent);
    }

    private static function footprintIs(
        int $gx,
        int $gy,
        int $w,
        int $h,
        callable $test,
        ?array $extent = null,
    ): bool {
        if ($w < 1 || $h < 1) {
            return false;
        }

        [$cols, $rows] = $extent ?? self::extent();
        $ground = self::ground([$cols, $rows]);

        for ($x = $gx; $x < $gx + $w; $x++) {
            for ($y = $gy; $y < $gy + $h; $y++) {
                if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows) {
                    return false;
                }

                if (! $test($ground[$y][$x], $x, $y)) {
                    return false;
                }
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Designs
    |--------------------------------------------------------------------------
    */

    /**
     * What can be built, and what it looks like.
     *
     * Every office used to be the one `office` sprite at one of two sizes, so
     * twenty-one buildings differed in nothing but colour — which is a chart of
     * offices rather than a compound. A template is a sprite, a style, a
     * footprint and a height, and between them they make buildings that are
     * obviously the same town and obviously not each other.
     *
     * `kind` decides what the thing is once it is standing there: an office has
     * a department behind it and a panel with doors in it, scenery has neither.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function templates(): array
    {
        return [
            /* -------------------------------------------------- offices --- */
            ['id' => 'office', 'kind' => 'office', 'category' => 'offices', 'name' => 'Office',
                'blurb' => 'The ordinary two-storey block', 'paint' => true,
                'sprite' => 'office', 'style' => 'plain', 'w' => 2, 'h' => 2, 'height' => 26],

            ['id' => 'office-wide', 'kind' => 'office', 'category' => 'offices', 'name' => 'Wide office',
                'blurb' => 'Room for a bigger staff', 'paint' => true,
                'sprite' => 'office', 'style' => 'plain', 'w' => 3, 'h' => 2, 'height' => 30],

            ['id' => 'tower', 'kind' => 'office', 'category' => 'offices', 'name' => 'Tower',
                'blurb' => 'Four storeys on a small footprint', 'paint' => true,
                'sprite' => 'office', 'style' => 'plain', 'w' => 2, 'h' => 2, 'height' => 48],

            ['id' => 'hall', 'kind' => 'office', 'category' => 'offices', 'name' => 'Hall',
                'blurb' => 'A portico and a pediment', 'paint' => true,
                'sprite' => 'office', 'style' => 'hall', 'w' => 3, 'h' => 2, 'height' => 44],

            ['id' => 'annex', 'kind' => 'office', 'category' => 'offices', 'name' => 'Annex',
                'blurb' => 'Low, long, deep roof', 'paint' => true,
                'sprite' => 'office', 'style' => 'annex', 'w' => 3, 'h' => 2, 'height' => 18],

            ['id' => 'warehouse', 'kind' => 'office', 'category' => 'offices', 'name' => 'Warehouse',
                'blurb' => 'A big door and no windows', 'paint' => true,
                'sprite' => 'office', 'style' => 'shed', 'w' => 4, 'h' => 2, 'height' => 26],

            /* -------------------------------------------------- shelter --- */
            ['id' => 'shed', 'kind' => 'scenery', 'category' => 'shelter', 'name' => 'Waiting shed',
                'blurb' => 'A roof on four posts, and a bench', 'paint' => false,
                'sprite' => 'shed', 'style' => 'plain', 'w' => 2, 'h' => 1, 'height' => 16],

            ['id' => 'tent', 'kind' => 'scenery', 'category' => 'shelter', 'name' => 'Tent',
                'blurb' => 'For registration day and the fiesta', 'paint' => true,
                'sprite' => 'tent', 'style' => 'plain', 'w' => 2, 'h' => 2, 'height' => 22],

            ['id' => 'bench', 'kind' => 'scenery', 'category' => 'shelter', 'name' => 'Bench',
                'blurb' => 'Somewhere to wait', 'paint' => false,
                'sprite' => 'bench', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 8],

            /* ---------------------------------------------------- civic --- */
            ['id' => 'gate', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Gate',
                'blurb' => 'Two pillars, an arch and the guardhouse', 'paint' => false,
                'sprite' => 'gate', 'style' => 'plain', 'w' => 3, 'h' => 1, 'height' => 24],

            ['id' => 'flagpole', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Flagpole',
                'blurb' => 'For the Monday ceremony', 'paint' => false,
                'sprite' => 'flagpole', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 54],

            ['id' => 'monument', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Monument',
                'blurb' => 'A plinth and somebody on it', 'paint' => false,
                'sprite' => 'monument', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 30],

            ['id' => 'fountain', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Fountain',
                'blurb' => 'The plaza needs one', 'paint' => false,
                'sprite' => 'fountain', 'style' => 'plain', 'w' => 2, 'h' => 2, 'height' => 18],

            ['id' => 'court', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Covered court',
                'blurb' => 'Liga starts at five', 'paint' => true,
                'sprite' => 'court', 'style' => 'plain', 'w' => 4, 'h' => 3, 'height' => 26],

            ['id' => 'sign', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Signboard',
                'blurb' => 'Which way to which office', 'paint' => false,
                'sprite' => 'sign', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 16],

            ['id' => 'lamp', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Lamp post',
                'blurb' => 'Lit after five', 'paint' => false,
                'sprite' => 'lamp', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 26],

            ['id' => 'jeepney', 'kind' => 'scenery', 'category' => 'civic', 'name' => 'Jeepney',
                'blurb' => 'Leaves when full', 'paint' => false,
                'sprite' => 'jeepney', 'style' => 'plain', 'w' => 2, 'h' => 1, 'height' => 18],

            /* -------------------------------------------------- planting --- */
            ['id' => 'tree', 'kind' => 'scenery', 'category' => 'planting', 'name' => 'Tree',
                'blurb' => 'Shade, eventually', 'paint' => false,
                'sprite' => 'tree', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 22],

            ['id' => 'palm', 'kind' => 'scenery', 'category' => 'planting', 'name' => 'Coconut palm',
                'blurb' => 'Do not park under it', 'paint' => false,
                'sprite' => 'palm', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 26],

            ['id' => 'bush', 'kind' => 'scenery', 'category' => 'planting', 'name' => 'Bush',
                'blurb' => 'For the edges', 'paint' => false,
                'sprite' => 'bush', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 10],

            ['id' => 'flowers', 'kind' => 'scenery', 'category' => 'planting', 'name' => 'Flower bed',
                'blurb' => 'Somebody waters these', 'paint' => false,
                'sprite' => 'flowers', 'style' => 'plain', 'w' => 2, 'h' => 1, 'height' => 6],

            /* ------------------------------------------------- boundary --- */
            ['id' => 'wall', 'kind' => 'scenery', 'category' => 'boundary', 'name' => 'Wall',
                'blurb' => 'One length of it', 'paint' => true,
                'sprite' => 'wall', 'style' => 'plain', 'w' => 1, 'h' => 1, 'height' => 14],

            ['id' => 'wall-long', 'kind' => 'scenery', 'category' => 'boundary', 'name' => 'Long wall',
                'blurb' => 'Three lengths in one go', 'paint' => true,
                'sprite' => 'wall', 'style' => 'plain', 'w' => 3, 'h' => 1, 'height' => 14],
        ];
    }

    /**
     * The tabs in the builder, in the order somebody thinks about them.
     *
     * Ground and land are not templates — nothing is placed, the ground itself
     * changes — but they belong in the same panel, because "make the compound
     * bigger" and "put something on it" are the same errand.
     *
     * `icon` names the template each tab is drawn with. A row of small words
     * was the one part of this panel that did not show you anything, on a
     * screen whose whole subject is what things look like — so a tab is now the
     * thing it contains, drawn with that thing's own code. Two of them name no
     * template because they place nothing; the renderer draws those itself.
     *
     * @return array<int, array<string, string>>
     */
    public static function categories(): array
    {
        return [
            ['id' => 'offices', 'name' => 'Offices', 'icon' => 'office',
                'blurb' => 'Buildings with a department behind them'],

            ['id' => 'shelter', 'name' => 'Shelter', 'icon' => 'shed',
                'blurb' => 'Sheds, tents and somewhere to sit'],

            /* The fountain rather than the flagpole, which at the size of a tab
               is a stick. A tab has to be recognisable at a glance or it is
               just a smaller version of the word it replaced. */
            ['id' => 'civic', 'name' => 'Civic', 'icon' => 'fountain',
                'blurb' => 'The gate, the flagpole, the court'],

            ['id' => 'planting', 'name' => 'Planting', 'icon' => 'tree',
                'blurb' => 'Trees, palms and beds'],

            ['id' => 'boundary', 'name' => 'Walls', 'icon' => 'wall-long',
                'blurb' => 'Where the compound stops'],

            ['id' => 'ground', 'name' => 'Ground', 'icon' => 'paving',
                'blurb' => 'Paths and paving'],

            ['id' => 'land', 'name' => 'Land', 'icon' => 'plot',
                'blurb' => 'Take in more ground, or give some back'],
        ];
    }

    /**
     * The brushes in the Ground tab.
     *
     * @return array<int, array<string, string>>
     */
    public static function brushes(): array
    {
        return [
            ['id' => 'r', 'name' => 'Path', 'blurb' => 'Compacted earth between the blocks'],
            ['id' => 'p', 'name' => 'Paving', 'blurb' => 'The plaza treatment'],
            ['id' => 'g', 'name' => 'Grass', 'blurb' => 'Lift whatever is there'],
        ];
    }

    /**
     * The colours a building may be painted.
     *
     * A fixed set rather than a colour picker, because two arbitrary hex values
     * is how one building ends up the colour of a bruise and the compound stops
     * looking like one place. Every pair here already appears in the town.
     *
     * @return array<int, array<string, string>>
     */
    public static function palette(): array
    {
        return [
            ['name' => 'Teal', 'wall' => '#ede3d2', 'roof' => '#2e7d7b'],
            ['name' => 'Rust', 'wall' => '#f2f0e6', 'roof' => '#c1462f'],
            ['name' => 'Brown', 'wall' => '#e4d9bf', 'roof' => '#8e5a3c'],
            ['name' => 'Slate', 'wall' => '#d9ddd6', 'roof' => '#56707f'],
            ['name' => 'Plum', 'wall' => '#e9e2d0', 'roof' => '#7a4e7e'],
            ['name' => 'Amber', 'wall' => '#f4ecda', 'roof' => '#e0a526'],
            ['name' => 'Pine', 'wall' => '#ede3d2', 'roof' => '#1f6b69'],
            ['name' => 'Ash', 'wall' => '#e9e2d0', 'roof' => '#5d6a73'],
            ['name' => 'Leaf', 'wall' => '#f2f0e6', 'roof' => '#6fa84f'],
            ['name' => 'Indigo', 'wall' => '#e4d9bf', 'roof' => '#4a3f7a'],
        ];
    }

    /**
     * Offices with nowhere to stand yet.
     *
     * What the add panel offers before it offers to create a new one: a
     * department that exists and has no building is far more likely to be what
     * somebody means than a department that does not exist at all.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function officesWithoutABuilding(): array
    {
        return Department::query()
            ->internal()
            ->whereDoesntHave('building')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'short_name'])
            ->map(fn (Department $office) => [
                'id' => $office->getKey(),
                'code' => $office->code,
                'name' => $office->displayName(),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | The buildings
    |--------------------------------------------------------------------------
    */

    /**
     * Everything standing in the compound, nearest the front first.
     *
     * The order is only a convenience for the plain list below the drawing —
     * the renderer sorts by depth itself, because which building is in front of
     * which depends on where the camera is and not on what SQL returned.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function places(?User $user = null): array
    {
        $buildings = CompoundBuilding::query()
            ->with('department.head')
            ->inDrawingOrder()
            ->get();

        $notices = self::noticesByOffice($buildings->pluck('department_id')->filter());
        $photos = self::photosByLandmark($buildings);

        return $buildings
            ->map(fn (CompoundBuilding $building) => self::place($building, $user, $notices, $photos))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Collection<int, Announcement>>  $notices
     * @param  Collection<string, array<int, array<string, mixed>>>  $photos
     * @return array<string, mixed>
     */
    private static function place(
        CompoundBuilding $building,
        ?User $user,
        Collection $notices,
        Collection $photos,
    ): array {
        $office = $building->department;
        $id = $office ? self::landmarkKey($office) : 'scenery-'.$building->getKey();

        $place = [
            'id' => $id,
            'building' => $building->getKey(),
            'sprite' => $building->sprite,
            'style' => $building->style,
            'kind' => $office ? 'office' : 'scenery',

            'gx' => $building->gx,
            'gy' => $building->gy,
            'w' => $building->w,
            'h' => $building->h,
            'height' => $building->height,
            'wall' => $building->wall,
            'roof' => $building->roof,

            'photos' => $photos->get($id, []),
        ];

        if (! $office) {
            return $place + self::scenery($building);
        }

        return $place + [
            'name' => $office->displayName(),
            'blurb' => $office->code,
            'say' => $office->summary ?: 'An office of the '.config('lgu.name').'.',
            'facts' => self::facts($office),
            'notices' => self::noticeLinks($notices->get($office->getKey())),
            'links' => self::links($office, $user),
            'mine' => $user?->department_id === $office->getKey(),
        ];
    }

    /** The bits of scenery, which have no office behind them. */
    private static function scenery(CompoundBuilding $building): array
    {
        return match ($building->sprite) {
            'gate' => [
                'name' => 'The Gate',
                'blurb' => 'Guardhouse and the logbook',
                'say' => 'Sign the logbook on your way in. Yes, still. Some records are not ours to modernise.',
            ],
            'flagpole' => [
                'name' => 'The Flagpole',
                'blurb' => 'Monday morning, 8am sharp',
                'say' => 'Flag ceremony every Monday, rain or shine, in front of the whole compound.',
            ],
            'shed' => [
                'name' => 'Waiting Shed',
                'blurb' => 'Where you wait for the 3pm signature',
                'say' => 'Somebody has been waiting here since ten. Their document is with the Mayor.',
            ],
            'jeepney' => [
                'name' => 'The Jeepney',
                'blurb' => 'Barangay route, leaves when full',
                'say' => 'Leaves when full. It has been almost full since this morning.',
            ],
            default => [
                'name' => 'The Compound',
                'blurb' => 'Part of the grounds',
                'say' => 'Just part of the grounds.',
            ],
        };
    }

    /**
     * The facts on a nameplate.
     *
     * The head is named because a head of office is a public official acting in
     * that capacity, which is exactly the thing a citizen looking for the right
     * door needs to know. Nobody else in the office is listed.
     *
     * @return array<int, array<string, string>>
     */
    private static function facts(Department $office): array
    {
        return array_values(array_filter([
            ['label' => 'Office code', 'value' => $office->code],
            $office->head ? ['label' => 'Head of office', 'value' => $office->head->name] : null,
            ['label' => 'On this system', 'value' => $office->is_onboarded ? 'Yes' : 'Not yet'],
        ]));
    }

    /**
     * What this person may open from this building.
     *
     * Only their own office offers doors, and the doors are exactly
     * Navigation::forCurrentUser() — the sidebar's list, which is already
     * filtered to what this account may open. So the compound can never offer a
     * screen the sidebar would not, which is the guarantee the old
     * building-per-screen compound rested on, kept in the one place it still
     * means something.
     *
     * Another office's building has no doors. Not because looking is forbidden
     * — the directory is public — but because there is nothing behind it for
     * somebody who does not work there.
     *
     * @return array<int, array<string, string>>
     */
    private static function links(Department $office, ?User $user): array
    {
        if (! $user || $user->department_id !== $office->getKey()) {
            return [];
        }

        return collect(Navigation::forCurrentUser())
            ->reject(fn (array $item) => $item['icon'] === 'compound')
            ->map(fn (array $item) => ['label' => $item['label'], 'url' => $item['url']])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $officeIds
     * @return Collection<int, Collection<int, Announcement>>
     */
    private static function noticesByOffice(Collection $officeIds): Collection
    {
        if ($officeIds->isEmpty()) {
            return collect();
        }

        return Announcement::query()
            ->live()
            ->forTheFrontPage()
            ->whereIn('department_id', $officeIds->all())
            ->get(['id', 'title', 'slug', 'department_id', 'published_at', 'is_pinned'])
            ->groupBy('department_id')
            ->map(fn (Collection $group) => $group->take(3));
    }

    /**
     * @param  Collection<int, Announcement>|null  $notices
     * @return array<int, array<string, string>>
     */
    private static function noticeLinks(?Collection $notices): array
    {
        return collect($notices)
            ->map(fn (Announcement $notice) => [
                'title' => $notice->title,
                'url' => route('public.announcement', $notice),
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Photographs
    |--------------------------------------------------------------------------
    */

    /**
     * The key an office's photographs are filed under.
     *
     * Prefixed so it cannot collide with a town landmark: 'court' is the covered
     * court, and an office whose code happened to be COURT would otherwise
     * quietly share its pictures.
     */
    public static function landmarkKey(Department $office): string
    {
        return 'office:'.$office->code;
    }

    /**
     * Every office that can hold photographs, key => name, for the admin
     * screen's picker. Built from the same table the compound is drawn from.
     *
     * @return array<string, string>
     */
    public static function landmarks(): array
    {
        return CompoundBuilding::query()
            ->whereNotNull('department_id')
            ->with('department')
            ->get()
            ->filter(fn (CompoundBuilding $b) => $b->department !== null)
            ->sortBy(fn (CompoundBuilding $b) => $b->department->sort_order)
            ->mapWithKeys(fn (CompoundBuilding $b) => [
                self::landmarkKey($b->department) => $b->department->displayName(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, CompoundBuilding>  $buildings
     * @return Collection<string, array<int, array<string, mixed>>>
     */
    private static function photosByLandmark(Collection $buildings): Collection
    {
        $keys = $buildings
            ->map(fn (CompoundBuilding $b) => $b->department ? self::landmarkKey($b->department) : null)
            ->filter()
            ->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        return LandmarkPhoto::query()
            ->live()
            ->whereIn('landmark', $keys->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('file')
            ->get()
            ->groupBy('landmark')
            ->map(fn (Collection $group) => $group
                ->map(fn (LandmarkPhoto $photo) => $photo->forThePayload())
                ->values()
                ->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Words
    |--------------------------------------------------------------------------
    */

    /** @return array<int, string> */
    private static function intro(?User $user): array
    {
        $name = str($user?->name ?? '')->before(' ')->toString();

        return array_values(array_filter([
            $user === null
                ? 'This is the compound — every office of the municipality, drawn.'
                : ($name === '' ? 'Welcome back.' : 'Welcome back, '.$name.'.'),

            'Click a building to see what that office does and who heads it.',

            $user?->department
                ? 'Yours is marked. Press "Take me there" and I will walk you over.'
                : 'Nothing here needs an account — every door behind them still does.',
        ]));
    }

    /** @return array<int, string> */
    private static function tips(): array
    {
        return [
            'Tip: the buildings are the offices themselves, not the screens. Those are still in the sidebar.',
            'Tip: your own office is the one with the marker over it.',
            'Tip: a taller building is a bigger office. It is not more important, it just has more people in it.',
            'Tip: the flagpole is where the Monday ceremony happens.',
            'Tip: if all the movement is distracting, the gear button turns it off.',
        ];
    }
}
