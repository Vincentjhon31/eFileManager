<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Enums\DisclosureCategory;
use App\Models\Announcement;
use App\Models\PublicFile;
use App\Support\World;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The municipality's public page.
 *
 * Plain controllers and Blade rather than Livewire, deliberately. This is the
 * one part of the system whose audience is not the staff: it is read on old
 * phones on patchy signal, by people who did not choose to use this software.
 * Filters are ordinary GET parameters, every page works with JavaScript off,
 * and every listing is a link somebody can send to somebody else.
 *
 * Every query here goes through a Live scope. There is no parameter on any of
 * these pages that reaches an unpublished record, and no code path from the
 * public side into the drive, the routing engine or the audit trail.
 */
class PublicPortalController extends Controller
{
    /**
     * How much post the mailbox holds.
     *
     * Two queries of this size are merged and the total is cut back to it
     * again, so the page is bounded whichever side is busier.
     */
    private const MAILBOX_ITEMS = 30;

    public function home(): View
    {
        $shelves = $this->shelfCounts();

        $noticeCount = Announcement::query()->live()->forTheFrontPage()->count();
        $disclosureCount = array_sum(array_column($shelves, 'count'));

        return view('public.home', [
            /*
             * The drawn town.
             *
             * The landmarks carry the same two counts the old hero showed as a
             * pair of statistics — a red 4 over the notice board is the same
             * fact as "Active notices: 4", read at a glance instead of parsed.
             * See App\Support\World for what each landmark is and why the list
             * lives in PHP rather than in the renderer.
             */
            'world' => World::payload($noticeCount, $disclosureCount),

            'pinned' => Announcement::query()->live()->where('is_pinned', true)
                ->forTheFrontPage()->with('department')->limit(3)->get(),

            'latest' => Announcement::query()->live()->where('is_pinned', false)
                ->forTheFrontPage()->with('department')->limit(6)->get(),

            'recentDisclosures' => PublicFile::query()->live()->onTheBoard()
                ->with('file')->orderByDesc('published_at')->limit(5)->get(),

            'shelves' => $shelves,
        ]);
    }

    /**
     * The mailbox: everything the hall has put out, in one list.
     *
     * The town has a notice board and a disclosure fountain, and both are
     * organised the way the office that fills them thinks — by kind, by shelf,
     * by fiscal year. A citizen walking past does not think in shelves. They
     * think "has anything come out lately", which is one list in date order,
     * and that is what a mailbox is.
     *
     * Deliberately not paginated and deliberately not searchable. This is the
     * recent post, not the archive: past the end of it are the two full
     * listings, each of which already does searching and filtering properly.
     * Merging in PHP rather than as a union query is what that bound buys —
     * one page of each table, sorted together, and no cross-table pagination
     * to get subtly wrong.
     */
    public function mailbox(): View
    {
        $notices = Announcement::query()->live()->forTheFrontPage()
            ->with('department')->limit(self::MAILBOX_ITEMS)->get();

        $files = PublicFile::query()->live()->onTheBoard()
            ->with('file')->orderByDesc('published_at')->limit(self::MAILBOX_ITEMS)->get();

        return view('public.mailbox', [
            'items' => $notices->concat($files)
                ->sortByDesc('published_at')
                ->take(self::MAILBOX_ITEMS)
                ->values(),
            'noticeCount' => $notices->count(),
            'fileCount' => $files->count(),
        ]);
    }

    public function announcements(Request $request): View
    {
        $category = AnnouncementCategory::tryFrom((string) $request->query('category'));
        $search = trim((string) $request->query('q', ''));

        $announcements = Announcement::query()
            ->live()
            ->with('department')
            ->when($category, fn ($q) => $q->where('category', $category->value))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($sub) => $sub->where('title', 'like', $term)
                    ->orWhere('excerpt', 'like', $term)
                    ->orWhere('body', 'like', $term));
            })
            ->forTheFrontPage()
            ->paginate(12)
            ->withQueryString();

        return view('public.announcements', [
            'announcements' => $announcements,
            'categories' => AnnouncementCategory::all(),
            'category' => $category,
            'search' => $search,
        ]);
    }

    public function announcement(Announcement $announcement): View
    {
        // Route-model binding finds it by slug; this is what decides whether
        // the public may read it. A draft is a 404, not a 403 — the existence
        // of an unpublished notice is not the public's business either.
        abort_unless($announcement->isLive(), 404);

        return view('public.announcement', [
            'announcement' => $announcement->load('department'),
            'attachments' => $announcement->attachments()->live()->with('file')->get(),
            'more' => Announcement::query()->live()
                ->whereKeyNot($announcement->getKey())
                ->forTheFrontPage()->limit(4)->get(),
        ]);
    }

    /**
     * The Full Disclosure Policy board.
     *
     * The DILG requires an LGU to post certain documents where citizens can
     * read them, which makes this page a compliance deliverable rather than a
     * courtesy — and the reason it is worth keeping current.
     */
    public function disclosure(Request $request): View
    {
        $category = DisclosureCategory::tryFrom((string) $request->query('category'));
        $year = $request->integer('year') ?: null;
        $search = trim((string) $request->query('q', ''));

        $files = PublicFile::query()
            ->live()
            ->onTheBoard()
            ->with('file')
            ->when($category, fn ($q) => $q->where('category', $category->value))
            ->when($year, fn ($q) => $q->where('fiscal_year', $year))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($sub) => $sub->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->orderByDesc('fiscal_year')
            ->orderBy('title')
            ->paginate(25)
            ->withQueryString();

        return view('public.disclosure', [
            'files' => $files,
            'categories' => DisclosureCategory::all(),
            'category' => $category,
            'years' => PublicFile::query()->live()->onTheBoard()
                ->whereNotNull('fiscal_year')
                ->distinct()->orderByDesc('fiscal_year')->pluck('fiscal_year'),
            'year' => $year,
            'search' => $search,
            'shelves' => $this->shelfCounts(),
        ]);
    }

    /** How much is on each shelf, for the board's navigation. */
    private function shelfCounts(): array
    {
        $counts = PublicFile::query()->live()->onTheBoard()
            ->groupBy('category')
            ->selectRaw('category, count(*) as total')
            ->pluck('total', 'category');

        return collect(DisclosureCategory::all())
            ->map(fn (DisclosureCategory $c) => [
                'category' => $c,
                'count' => (int) ($counts[$c->value] ?? 0),
            ])
            ->all();
    }
}
