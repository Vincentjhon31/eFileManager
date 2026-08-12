<?php

namespace App\Support;

/**
 * The staff side of the hall, as a place you can walk through.
 *
 * Same idea as App\Support\World, and the same split: this class decides what
 * the buildings are and where their doors lead, and resources/js/world.js draws
 * whatever it is handed. The difference is where the list comes from.
 *
 * It comes from Navigation::forCurrentUser() — the same call the sidebar and the
 * tour are built from, and for the same reason. A clerk who cannot open Storage
 * & Backups has no building for it in their compound; a screen added to the
 * sidebar tomorrow needs a line of copy here or it is silently left out. The
 * compound can never offer a door that the sidebar does not, or lead somewhere
 * the person standing in it would be turned away from.
 *
 * Which also means this is presentation only, exactly like the sidebar. Hiding a
 * building is not access control — every destination is still guarded by its own
 * middleware and policy, and somebody who types the URL is stopped there.
 */
class Compound
{
    /**
     * One building per destination, keyed by the icon Navigation already assigns.
     *
     * The name is deliberately not invented: it is whatever the sidebar calls the
     * screen, taken from Navigation at runtime, because somebody hunting for
     * "Audit trail" should not have to work out that it is the building with the
     * magnifying glass. What is invented is everything else — the colours, the
     * height, the emblem over the door — and that is what makes twelve buildings
     * in a row tellable apart at a glance.
     *
     * `motif` names a function in the MOTIFS table in world.js. A key with no
     * matching motif still draws a building, just with an empty plaque.
     *
     * @var array<string, array<string, mixed>>
     */
    private const BUILDINGS = [
        'dashboard' => [
            'blurb' => 'The four numbers, every morning',
            'width' => 130,
            'style' => ['motif' => 'dashboard', 'roof' => '#2e7d7b', 'wall' => '#ede3d2', 'storeys' => 2],
            'say' => 'Start here. What arrived, what is on your desk, what you are waiting on, what is late.',
        ],
        'desk' => [
            'blurb' => 'Your queue for the day',
            'width' => 118,
            'style' => ['motif' => 'desk', 'roof' => '#c1462f', 'wall' => '#f2f0e6', 'storeys' => 1],
            'say' => 'Everything waiting on you, sorted into tabs instead of one long list.',
        ],
        'documents' => [
            'blurb' => 'Every document your office can see',
            'width' => 134,
            'style' => ['motif' => 'documents', 'roof' => '#8e5a3c', 'wall' => '#e4d9bf', 'storeys' => 2],
            'say' => 'Where a document gets its tracking number and starts the trail that follows it everywhere after.',
        ],
        'workspace' => [
            'blurb' => 'The other systems the LGU runs',
            'width' => 112,
            'style' => ['motif' => 'workspace', 'roof' => '#6750a4', 'wall' => '#ede3d2', 'storeys' => 1],
            'say' => 'One click instead of a bookmark somebody emailed you two years ago.',
        ],
        'drive' => [
            'blurb' => "Your office's filing cabinet",
            'width' => 126,
            'style' => ['motif' => 'drive', 'roof' => '#56707f', 'wall' => '#d9ddd6', 'storeys' => 2],
            'say' => 'Folders, uploads and versions kept rather than overwritten — and a trash that means recoverable.',
        ],
        'offices' => [
            'blurb' => 'Every office a document can reach',
            'width' => 118,
            'style' => ['motif' => 'offices', 'roof' => '#7a4e7e', 'wall' => '#e9e2d0', 'storeys' => 1],
            'say' => 'Onboarding an office is the switch from receipts logged by hand to receipts logged here.',
        ],
        'users' => [
            'blurb' => 'Accounts, and what each may act on',
            'width' => 112,
            'style' => ['motif' => 'users', 'roof' => '#6fa84f', 'wall' => '#f2f0e6', 'storeys' => 1],
            'say' => 'Nobody signs themselves up. Every account started with somebody in this screen.',
        ],
        'apps' => [
            'blurb' => 'What appears in the Workspace',
            'width' => 106,
            'style' => ['motif' => 'apps', 'roof' => '#4a3f7a', 'wall' => '#e4d9bf', 'storeys' => 1],
            'say' => 'The registry behind the Workspace — what is listed, who sees it, and whether it is live.',
        ],
        'notices' => [
            'blurb' => 'What the public reads',
            'width' => 118,
            'style' => ['motif' => 'notices', 'roof' => '#e0a526', 'wall' => '#f4ecda', 'storeys' => 1],
            'say' => 'What goes on the town page before anybody outside the hall reads it.',
        ],
        'disclosure' => [
            'blurb' => 'Public by law, permanent once posted',
            'width' => 124,
            'style' => ['motif' => 'disclosure', 'roof' => '#1f6b69', 'wall' => '#ede3d2', 'storeys' => 1],
            'say' => 'There is no unpublishing a disclosure — only marking it withdrawn.',
        ],
        'audit' => [
            'blurb' => 'Who did what, and when',
            'width' => 118,
            'style' => ['motif' => 'audit', 'roof' => '#5d6a73', 'wall' => '#e9e2d0', 'storeys' => 2],
            'say' => 'Written automatically by every other building in this compound, and editable by no one.',
        ],
        'storage' => [
            'blurb' => 'Space, and the backup button',
            'width' => 112,
            'style' => ['motif' => 'storage', 'roof' => '#3f5b6b', 'wall' => '#d9ddd6', 'storeys' => 1],
            'say' => 'Where the drive\'s space is going, and the button to press before anything that makes you nervous.',
        ],
    ];

    /**
     * The compound, left to right.
     *
     * Scenery is threaded between the two groups Navigation already draws —
     * "work", which any onboarded employee touches, and "admin", which only
     * appears for somebody who can act on it. The gate opens the row, the
     * flagpole and its benches mark the join, and the shed and the jeepney close
     * it. That boundary is a real one in this system, so it is worth being able
     * to see where it falls.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function places(): array
    {
        $nav = collect(Navigation::forCurrentUser())
            ->filter(fn (array $item) => isset(self::BUILDINGS[$item['icon']]));

        $buildingsFor = fn (string $group) => $nav
            ->where('group', $group)
            ->map(fn (array $item) => self::building($item))
            ->values()
            ->all();

        $work = $buildingsFor('work');
        $admin = $buildingsFor('admin');

        return array_values(array_filter([
            self::scenery('gate', 'The Gate', 'Guardhouse and the logbook', 'plaza',
                'Sign the logbook on your way in. Yes, still. Some records are not ours to modernise.'),

            ...$work,

            /* Only where there is an admin wing to divide off. For a clerk the
               flagpole would be marking the end of the row, which is not what a
               plaza in the middle of a compound means. */
            $admin === [] ? null : self::scenery('flagpole', 'The Flagpole', 'Monday morning, 8am sharp', 'plaza',
                'Flag ceremony every Monday. Past here is the administrative wing.'),

            ...$admin,

            self::scenery('shed', 'Waiting Shed', 'Where you wait for the 3pm signature', 'plaza',
                'Somebody has been waiting here since ten. Their document is with the Mayor.'),

            self::scenery('jeepney', 'The Jeepney', 'Barangay route, leaves when full', 'plaza',
                'Leaves when full. It has been almost full since this morning.'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $item  One entry from Navigation::forCurrentUser().
     * @return array<string, mixed>
     */
    private static function building(array $item): array
    {
        $spec = self::BUILDINGS[$item['icon']];

        return [
            'id' => $item['icon'],
            'sprite' => 'office',
            'name' => $item['label'],
            'blurb' => $spec['blurb'],
            'kind' => 'link',
            'url' => $item['url'],
            'ground' => 'plaza',
            'width' => $spec['width'],
            'style' => $spec['style'],
            'say' => $spec['say'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function scenery(string $sprite, string $name, string $blurb, string $ground, string $say): array
    {
        return [
            'id' => $sprite,
            'sprite' => $sprite,
            'name' => $name,
            'blurb' => $blurb,
            'kind' => 'scenery',
            'ground' => $ground,
            'say' => $say,
        ];
    }

    /**
     * Everything the renderer needs. Mirrors World::payload — see the note there
     * on why this is assembled in PHP rather than in the template.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'places' => self::places(),
            'intro' => self::intro(),
            'tips' => self::tips(),
            'title' => World::shortName(),
            'subtitle' => 'The Compound',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function intro(): array
    {
        $name = str(auth()->user()?->name ?? '')->before(' ')->toString();

        return array_values(array_filter([
            $name === '' ? 'Welcome back.' : 'Welcome back, '.$name.'.',
            'This is the compound — one building for every screen you can open.',
            'Anything not on this row is something your account cannot reach.',
            'The sidebar is still there if you would rather go straight in.',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private static function tips(): array
    {
        return [
            'Tip: drag sideways, or use the arrow keys, to see the rest of the compound.',
            'Tip: the flagpole marks where the administrative wing starts.',
            'Tip: the emblem over each door is the same one the sidebar uses.',
            'Tip: everything here is also in the sidebar — this is the scenic route.',
            'Tip: the gate logbook is not a feature. Some records are still paper.',
        ];
    }
}
