<?php

namespace Tests\Feature\Public;

use App\Models\Announcement;
use App\Models\PublicFile;
use App\Support\World;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mailbox.
 *
 * It reads two tables that each already have a Live scope, and the whole risk
 * is that merging them loses one. So the assertions here are the same ones the
 * notice board and the disclosure board make, asked again of the combined list:
 * a draft, a withdrawal and an expiry must be as invisible here as they are
 * anywhere else on the public side.
 */
class MailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_needs_no_account(): void
    {
        $this->get(route('public.mailbox'))->assertOk()->assertSee('The mailbox');
    }

    public function test_it_shows_notices_and_disclosed_documents_together(): void
    {
        Announcement::factory()->published()->create(['title' => 'Water interruption advisory']);
        PublicFile::factory()->published()->create(['title' => '2026 Annual Budget']);

        $this->get(route('public.mailbox'))
            ->assertOk()
            ->assertSee('Water interruption advisory')
            ->assertSee('2026 Annual Budget')
            ->assertSee('Notice')
            ->assertSee('Document');
    }

    public function test_nothing_unpublished_reaches_it(): void
    {
        Announcement::factory()->create(['title' => 'Unfinished advisory']);
        Announcement::factory()->withdrawn()->create(['title' => 'Retracted advisory']);
        Announcement::factory()->expired()->create(['title' => 'Yesterdays advisory']);
        PublicFile::factory()->create(['title' => 'Not yet public budget']);
        PublicFile::factory()->withdrawn()->create(['title' => 'Old procurement plan']);

        $response = $this->get(route('public.mailbox'))->assertOk();

        foreach ([
            'Unfinished advisory', 'Retracted advisory', 'Yesterdays advisory',
            'Not yet public budget', 'Old procurement plan',
        ] as $hidden) {
            $response->assertDontSee($hidden);
        }
    }

    /** Newest first, whichever table it came from. */
    public function test_it_is_in_date_order_across_both_kinds(): void
    {
        Announcement::factory()->published()->create([
            'title' => 'Older notice',
            'published_at' => now()->subWeek(),
        ]);
        PublicFile::factory()->published()->create([
            'title' => 'Newer document',
            'published_at' => now()->subDay(),
        ]);

        $body = $this->get(route('public.mailbox'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'Older notice'),
            strpos($body, 'Newer document'),
            'the newer item should be listed first',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The landmark
    |--------------------------------------------------------------------------
    */

    public function test_the_town_has_a_mailbox_that_leads_to_it(): void
    {
        $mailbox = collect(World::publicPlaces(0, 0))->firstWhere('id', 'mailbox');

        $this->assertNotNull($mailbox, 'the town has no mailbox');
        $this->assertSame('link', $mailbox['kind']);
        $this->assertSame(route('public.mailbox'), $mailbox['url']);
    }

    /** The flag going up is driven by this, so it has to count both sides. */
    public function test_the_mailbox_badge_counts_notices_and_documents_together(): void
    {
        $mailbox = collect(World::publicPlaces(4, 7))->firstWhere('id', 'mailbox');

        $this->assertSame(11, $mailbox['badge']);
    }

    /**
     * Every sprite the town names must exist in the renderer, or the landmark
     * is silently skipped and the town has a gap in it. The compound has this
     * guarantee already; the public side did not, which is the gap this closes.
     */
    public function test_every_public_landmark_names_a_sprite_the_renderer_has(): void
    {
        $js = file_get_contents(resource_path('js/world.js'));

        foreach (World::publicPlaces(0, 0) as $place) {
            $this->assertMatchesRegularExpression(
                '/^\s{4}'.preg_quote($place['sprite'], '/').':\s*\{/m',
                $js,
                'world.js has no sprite named '.$place['sprite'],
            );
        }
    }

    /**
     * And the corner button's icon is drawn by name, so that name must exist.
     *
     * In chrome.js, not world.js: the corner button is on both drawn screens,
     * so its icons live with the rest of the shared furniture.
     */
    public function test_the_corner_button_icon_is_one_the_renderer_draws(): void
    {
        $js = file_get_contents(resource_path('js/world/chrome.js'));
        $home = file_get_contents(resource_path('views/public/home.blade.php'));

        $this->assertMatchesRegularExpression('/corner-icon="mailbox"/', $home);
        $this->assertStringContainsString("dataset.icon === 'mailbox'", $js);
    }
}
