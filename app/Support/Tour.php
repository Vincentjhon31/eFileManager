<?php

namespace App\Support;

/**
 * A short, guided walk around the sidebar for somebody's first visit.
 *
 * Deliberately built from the same list Navigation::forCurrentUser() already
 * produces rather than a second, hand-written menu: a clerk who cannot see
 * "Storage & Backups" is never told a story about a screen they cannot open,
 * and a link added to the sidebar tomorrow needs a line of copy here or it is
 * silently skipped — it can never appear out of step with what is actually on
 * screen.
 */
class Tour
{
    /**
     * One line of copy per destination, keyed by the icon name Navigation
     * already assigns it. Written for the person clicking through it on their
     * first day, not for a developer.
     *
     * @var array<string, array{title: string, body: string}>
     */
    private const STOPS = [
        'dashboard' => [
            'title' => 'Home base',
            'body' => 'The same four numbers every day: what just arrived, what is on your desk, '
                .'what you are still waiting to receive, and what is overdue. Everything else in this '
                .'tour is one of those numbers, seen from a different angle.',
        ],
        'compound' => [
            'title' => 'The Compound',
            'body' => 'The same list as this sidebar, drawn as a place: one building per screen you '
                .'can open, and none for the ones you cannot. Nothing lives only in there — it is the '
                .'scenic route to everywhere else, for the days when a list of twelve links is the '
                .'last thing you want to read.',
        ],
        'desk' => [
            'title' => 'My Desk',
            'body' => 'The queue, sorted into tabs instead of one long list — incoming, on your desk, '
                .'awaiting receipt, overdue. This is where you spend most of the day.',
        ],
        'documents' => [
            'title' => 'Documents',
            'body' => 'Every document your office can see, and where a new one is registered — the '
                .'moment it gets a tracking number and starts a trail that follows it everywhere after.',
        ],
        'workspace' => [
            'title' => 'Workspace',
            'body' => 'Other systems the LGU runs, one click away instead of a bookmark someone emailed '
                .'you two years ago.',
        ],
        'drive' => [
            'title' => 'Drive',
            'body' => 'Your office\'s own filing cabinet — folders, uploads, versions kept instead of '
                .'overwritten, and a trash that means what it says: recoverable, not gone.',
        ],
        'offices' => [
            'title' => 'Offices',
            'body' => 'Every office documents can be routed to, onboarded or not. Onboarding one is the '
                .'switch that moves it from "receipts logged by hand" to "receipts logged by this system."',
        ],
        'users' => [
            'title' => 'Users',
            'body' => 'Accounts and the roles that decide what each one can act on. Nobody signs '
                .'themselves up — every account here started with someone in this screen.',
        ],
        'notices' => [
            'title' => 'Notices',
            'body' => 'What goes on the public page before anyone outside the hall reads it.',
        ],
        'disclosure' => [
            'title' => 'Disclosure board',
            'body' => 'The Full Disclosure Policy board — public by law, and permanent once posted. '
                .'There is no unpublishing a disclosure, only marking it withdrawn.',
        ],
        'audit' => [
            'title' => 'Audit trail',
            'body' => 'Who did what, and when — written automatically, by every screen in this tour, '
                .'and editable by no one.',
        ],
        'storage' => [
            'title' => 'Storage & Backups',
            'body' => 'Where the drive\'s space is going office by office, and the button to press before '
                .'you attempt anything that makes you nervous.',
        ],
    ];

    /**
     * @return array<int, array{icon: ?string, label: ?string, title: string, body: string}>
     */
    public static function stepsFor(): array
    {
        $stops = collect(Navigation::forCurrentUser())
            ->filter(fn (array $item) => isset(self::STOPS[$item['icon']]))
            ->map(fn (array $item) => [
                'icon' => $item['icon'],
                'label' => $item['label'],
                'title' => self::STOPS[$item['icon']]['title'],
                'body' => self::STOPS[$item['icon']]['body'],
            ])
            ->values();

        return collect([self::intro()])
            ->concat($stops)
            ->push(self::outro())
            ->all();
    }

    /**
     * A centred card rather than a spotlight — there is nothing on screen yet
     * worth pointing at, only the shape of the whole trip a document takes.
     */
    private static function intro(): array
    {
        return [
            'icon' => null,
            'label' => null,
            'title' => 'Follow a document through the hall',
            'body' => 'Someone registers it, it gets routed to an office, that office receives it, '
                .'acts on it, and routes it on — or files it in the drive for good. Every stop in this '
                .'tour is one link in that chain. Ready?',
        ];
    }

    private static function outro(): array
    {
        return [
            'icon' => null,
            'label' => null,
            'title' => 'That\'s the whole hall',
            'body' => 'Nothing here is hidden once you know where to look — every number traces back '
                .'to a real document sitting in a real office. Lost again later? "Take the tour" is '
                .'always in the header.',
        ];
    }
}
