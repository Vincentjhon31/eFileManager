<?php

namespace App\Support;

/**
 * The town, as a place you can walk through.
 *
 * The welcome page is a drawn panorama of Bongabong rather than a page of
 * buttons. This class is the half of it that has opinions about meaning: what
 * each landmark *is*, what it is called, what it says, and where clicking it
 * actually goes. The other half — how many pixels wide the treehouse is and
 * which shade of green its leaves are — lives in resources/js/world.js.
 *
 * The split is deliberate. Routes, copy and counts change for reasons the
 * artwork does not care about, and a URL should never be spelled out in a
 * JavaScript file where nothing checks it still exists. So PHP hands over a
 * list and the renderer draws whatever it is given, in the order it is given.
 * Adding a landmark is a line here plus a sprite there; moving one is a line
 * here alone.
 *
 * Two kinds of landmark:
 *
 *   link    — goes somewhere. A real <a> is rendered for it, so the world
 *             works as an ordinary list of links with JavaScript switched off.
 *   scenery — goes nowhere. A treehouse, a fountain, a basketball court. It
 *             exists to be looked at, and says one friendly thing when
 *             clicked. Nothing behind it, nothing to break.
 *
 * Scenery is not decoration for its own sake: a page that looks like somewhere
 * is a page people are willing to look at, and this one is read by citizens who
 * did not choose to use this software.
 */
class World
{
    /**
     * Everything the renderer needs, in one object.
     *
     * Assembled here rather than in the template because it is handed over as a
     * JSON script tag, and a multi-line array literal inside @json() is more
     * than Blade's directive parser will take — it reads the first ']' it finds
     * as the end of the argument and compiles a syntax error. One variable, one
     * line in the template, and the structure of the payload stays somewhere it
     * can be read.
     *
     * @return array<string, mixed>
     */
    public static function payload(int $noticeCount, int $disclosureCount): array
    {
        return [
            'places' => self::publicPlaces($noticeCount, $disclosureCount),
            'intro' => self::intro(),
            'tips' => self::tips(),
            'title' => self::shortName(),
            'subtitle' => self::nameKind(),
        ];
    }

    /**
     * The town's name without the word for what kind of place it is.
     *
     * "Municipality of Bongabong" is the correct legal name and the wrong title
     * card — the splash has room for one word held large, and that word is the
     * place, not its classification. Falls back to the whole name for an LGU
     * whose configured name does not follow the pattern, which is the right
     * failure: a long title card beats an empty one.
     */
    public static function shortName(): string
    {
        $name = (string) config('lgu.name');

        foreach (['Municipality of ', 'City of ', 'Province of ', 'Barangay '] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return mb_strtoupper(mb_substr($name, mb_strlen($prefix)));
            }
        }

        return mb_strtoupper($name);
    }

    /**
     * The word shortName() took off, for the line under it. Empty when there was
     * nothing to take, so the splash simply shows one line instead of two.
     */
    public static function nameKind(): string
    {
        $name = (string) config('lgu.name');

        foreach (['Municipality', 'City', 'Province', 'Barangay'] as $kind) {
            if (str_starts_with($name, $kind.' ')) {
                return $kind;
            }
        }

        return '';
    }

    /**
     * The public panorama, left to right, as somebody walks into town.
     *
     * The order here is the order on screen. Sprite widths are the renderer's
     * business, so nothing in this list carries a coordinate — the world is
     * laid out by walking this array and asking each sprite how wide it is.
     * That means reordering the town is reordering this array, and no numbers
     * anywhere have to be corrected afterwards.
     *
     * @param  int  $noticeCount  Live notices, for the board's badge.
     * @param  int  $disclosureCount  Documents on the board, for the plaza's.
     * @return array<int, array<string, mixed>>
     */
    public static function publicPlaces(int $noticeCount, int $disclosureCount): array
    {
        $signedIn = auth()->check();

        return [
            /*
             * The store leads, and the treehouse follows it, because the
             * treehouse is the tallest thing in town and the title plaque sits
             * over the left-hand end of the view. Putting a low landmark first
             * keeps the two from fighting — and that this is a reordering of one
             * array, with no coordinate anywhere to correct afterwards, is the
             * whole reason the layout is walked rather than written down.
             */
            [
                'id' => 'store',
                'sprite' => 'store',
                'name' => 'Aling Nena\'s Store',
                'blurb' => 'Closed for merienda',
                'kind' => 'scenery',
                'ground' => 'grass',
                'say' => 'Sorry, walang load. Try the notice board — that one is always open.',
            ],
            [
                'id' => 'treehouse',
                'sprite' => 'treehouse',
                'name' => 'The Treehouse',
                'blurb' => 'What this system is, in plain words',
                'kind' => 'scenery',
                'ground' => 'grass',
                'say' => 'Every document in this town gets a tracking number and a trail. '
                    .'Nothing moves between offices without somebody signing for it.',
            ],
            [
                'id' => 'hall',
                'sprite' => 'hall',
                'name' => 'Municipal Hall',
                'blurb' => $signedIn ? 'Back to your desk' : 'Staff sign in',
                'kind' => 'link',
                // Signed in, the front door opens onto the compound rather than
                // straight onto a dashboard: somebody arriving through the drawn
                // town should find the drawn hall behind it, not a table.
                'url' => $signedIn ? route('compound') : route('login'),
                'ground' => 'plaza',
                'say' => $signedIn
                    ? 'Welcome back. The compound is through the front door.'
                    : 'Municipal staff only past this door — everything else in town is open to all.',
            ],
            [
                'id' => 'fountain',
                'sprite' => 'fountain',
                'name' => 'Plaza Fountain',
                'blurb' => 'Full Disclosure Policy board',
                'kind' => 'link',
                'url' => route('public.disclosure'),
                'badge' => $disclosureCount,
                'ground' => 'plaza',
                'say' => 'Budgets, procurement and financial statements are posted here, '
                    .'because the law says the town has to and because you paid for them.',
            ],
            [
                'id' => 'bulletin',
                'sprite' => 'bulletin',
                'name' => 'Notice Board',
                'blurb' => 'Public notices from the offices',
                'kind' => 'link',
                'url' => route('public.announcements'),
                'badge' => $noticeCount,
                'ground' => 'plaza',
                'say' => 'Suspensions, bidding notices, advisories. Pinned ones stay at the top.',
            ],
            [
                'id' => 'court',
                'sprite' => 'court',
                // Not "Barangay Court" — that is the Katarungang Pambarangay,
                // where disputes are settled, and this is where basketball is
                // played. One word avoids sending somebody looking for a hearing.
                'name' => 'Covered Court',
                'blurb' => 'Game at 5, as usual',
                'kind' => 'scenery',
                'ground' => 'plaza',
                'say' => 'Liga starts at five. The Engineering office has never once lost to Treasury.',
            ],
            [
                'id' => 'kiosk',
                'sprite' => 'kiosk',
                'name' => 'Citizen Kiosk',
                'blurb' => 'Forms you can print at home',
                'kind' => 'link',
                'url' => route('public.disclosure'),
                'ground' => 'grass',
                'say' => 'No account, no queue, no fee. Download it, print it, bring it filled in.',
            ],
            [
                'id' => 'beach',
                'sprite' => 'beach',
                'name' => 'Bongabong Shore',
                'blurb' => 'Oriental Mindoro, facing east',
                'kind' => 'scenery',
                'ground' => 'sand',
                'say' => 'The sun comes up over that water first, before anywhere else in town.',
            ],
        ];
    }

    /**
     * What the guide says on the way in, one line at a time.
     *
     * Kept here rather than in the renderer for the same reason as everything
     * else in this file: it is copy, it will be rewritten by somebody who does
     * not read JavaScript, and one of the lines names a landmark that this file
     * is also responsible for.
     *
     * @return array<int, string>
     */
    public static function intro(): array
    {
        return [
            'Mabuhay! Welcome to the '.config('lgu.name').'.',
            "I'm Mayor Mike — think of me as your guide around town.",
            'Everything here is open to the public. Click a landmark to go in.',
            'The notice board and the plaza are what most people come for.',
            'Salamat for visiting — take your time looking around!',
        ];
    }

    /**
     * Tips, offered when somebody clicks the guide instead of a landmark.
     *
     * @return array<int, string>
     */
    public static function tips(): array
    {
        return [
            'Tip: drag the town sideways, or use the arrow keys, to see the rest of it.',
            'Tip: a red number on a landmark is how many things are waiting to be read there.',
            'Tip: the plaza fountain is the Full Disclosure board — budgets and contracts.',
            'Tip: not everything is clickable. Some of it is just town.',
            'Tip: if all the movement is distracting, the gear button turns it off.',
        ];
    }
}
