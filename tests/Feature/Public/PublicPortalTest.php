<?php

namespace Tests\Feature\Public;

use App\Enums\AnnouncementCategory;
use App\Enums\DisclosureCategory;
use App\Models\Announcement;
use App\Models\File;
use App\Models\PublicFile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public page.
 *
 * The one gate that matters here is the Live scope: no filter, search term or
 * guessed id on any of these pages may reach a draft, a withdrawn disclosure,
 * or anything that has expired.
 */
class PublicPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Access, with no account at all
    |--------------------------------------------------------------------------
    */

    public function test_the_home_page_needs_no_account(): void
    {
        $this->get('/')->assertOk()->assertSee(config('lgu.name'));
    }

    public function test_notices_and_disclosure_pages_need_no_account(): void
    {
        $this->get(route('public.announcements'))->assertOk();
        $this->get(route('public.disclosure'))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    public function test_a_draft_notice_is_invisible_on_every_public_page(): void
    {
        $draft = Announcement::factory()->create(['title' => 'Unfinished advisory']);

        $this->get(route('public.home'))->assertDontSee('Unfinished advisory');
        $this->get(route('public.announcements'))->assertDontSee('Unfinished advisory');
    }

    /**
     * A draft's page is a 404, not a 403 — its existence is not the public's
     * business either way.
     */
    public function test_a_draft_notice_page_is_a_plain_not_found(): void
    {
        $draft = Announcement::factory()->create();

        $this->get(route('public.announcement', $draft))->assertNotFound();
    }

    public function test_a_published_notice_is_readable_by_anyone(): void
    {
        $announcement = Announcement::factory()->published()->create([
            'title' => 'Municipal fiesta schedule',
            'body' => "Line one.\n\nLine two.",
        ]);

        $this->get(route('public.announcement', $announcement))
            ->assertOk()
            ->assertSee('Municipal fiesta schedule')
            ->assertSee('Line one.', false)
            ->assertSee('Line two.', false);
    }

    public function test_a_withdrawn_notice_is_no_longer_reachable(): void
    {
        $announcement = Announcement::factory()->withdrawn()->create();

        $this->get(route('public.announcement', $announcement))->assertNotFound();
        $this->get(route('public.announcements'))->assertDontSee($announcement->title);
    }

    public function test_an_expired_notice_is_no_longer_reachable(): void
    {
        $announcement = Announcement::factory()->expired()->create(['title' => 'Yesterdays advisory']);

        $this->get(route('public.announcement', $announcement))->assertNotFound();
        $this->get(route('public.announcements'))->assertDontSee('Yesterdays advisory');
    }

    public function test_a_scheduled_notice_is_not_visible_before_its_time(): void
    {
        $announcement = Announcement::factory()->create([
            'published_at' => now()->addDay(),
            'published_by' => User::factory(),
        ]);

        $this->get(route('public.announcement', $announcement))->assertNotFound();
    }

    public function test_pinned_notices_are_shown_first_on_the_home_page(): void
    {
        $ordinary = Announcement::factory()->published()->create(['title' => 'Ordinary notice']);
        $pinned = Announcement::factory()->published()->pinned()->create(['title' => 'Important advisory']);

        $response = $this->get(route('public.home'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, 'Ordinary notice'),
            strpos($content, 'Important advisory'),
            'The pinned notice must render before the ordinary one.',
        );
    }

    public function test_notices_can_be_filtered_by_category(): void
    {
        Announcement::factory()->published()->category(AnnouncementCategory::Bidding)->create(['title' => 'Bid notice']);
        Announcement::factory()->published()->category(AnnouncementCategory::Event)->create(['title' => 'Fiesta event']);

        $this->get(route('public.announcements', ['category' => 'bidding']))
            ->assertSee('Bid notice')
            ->assertDontSee('Fiesta event');
    }

    public function test_the_notice_body_is_escaped_rather_than_rendered_as_html(): void
    {
        $announcement = Announcement::factory()->published()->create([
            'body' => '<script>alert(1)</script>',
        ]);

        $response = $this->get(route('public.announcement', $announcement));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Disclosure board
    |--------------------------------------------------------------------------
    */

    public function test_a_prepared_but_unpublished_file_does_not_appear_on_the_board(): void
    {
        $entry = PublicFile::factory()->create(['title' => 'Not yet public budget']);

        $this->get(route('public.disclosure'))->assertDontSee('Not yet public budget');
    }

    public function test_a_published_file_appears_on_the_board(): void
    {
        $entry = PublicFile::factory()->published()->create(['title' => '2026 Annual Budget']);

        $this->get(route('public.disclosure'))->assertSee('2026 Annual Budget');
    }

    public function test_a_withdrawn_file_no_longer_appears(): void
    {
        $entry = PublicFile::factory()->withdrawn()->create(['title' => 'Old procurement plan']);

        $this->get(route('public.disclosure'))->assertDontSee('Old procurement plan');
    }

    public function test_the_board_can_be_filtered_by_category_and_year(): void
    {
        PublicFile::factory()->published()->category(DisclosureCategory::AnnualBudget)
            ->fiscalYear(2026)->create(['title' => 'Budget 2026']);
        PublicFile::factory()->published()->category(DisclosureCategory::Procurement)
            ->fiscalYear(2025)->create(['title' => 'Procurement plan 2025']);

        $this->get(route('public.disclosure', ['category' => 'annual_budget']))
            ->assertSee('Budget 2026')
            ->assertDontSee('Procurement plan 2025');

        $this->get(route('public.disclosure', ['year' => 2025]))
            ->assertSee('Procurement plan 2025')
            ->assertDontSee('Budget 2026');
    }

    public function test_shelf_counts_only_count_what_is_actually_published(): void
    {
        PublicFile::factory()->published()->category(DisclosureCategory::Ordinance)->count(2)->create();
        PublicFile::factory()->category(DisclosureCategory::Ordinance)->create(); // prepared, not published

        $response = $this->get(route('public.home'));

        $response->assertOk();
        // Two published, not three.
        $response->assertSeeInOrder(['Ordinances and resolutions', '2 documents']);
    }

    /**
     * A search on the disclosure board must never surface anything from the
     * drive that was not explicitly nominated and published — it queries
     * public_files, never files.
     */
    public function test_disclosure_search_cannot_reach_the_drive_directly(): void
    {
        File::factory()->create(['name' => 'Confidential personnel file']);

        $this->get(route('public.disclosure', ['q' => 'Confidential']))
            ->assertDontSee('Confidential personnel file');
    }
}
