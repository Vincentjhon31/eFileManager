<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Support\Compound;
use App\Support\Navigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The compound draws one building per screen the signed-in employee can open.
 *
 * Almost everything worth asserting here is about that "can open": the screen is
 * a second shelf for the sidebar's links, and the moment it can show a door the
 * sidebar would not, it has become a way to advertise screens people will be
 * turned away from.
 */
class CompoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/compound')->assertRedirect(route('login'));
    }

    public function test_it_renders_for_a_signed_in_employee(): void
    {
        $this->actingAs($this->staff())
            ->get('/compound')
            ->assertOk()
            ->assertSee('The Compound')
            ->assertSee('Skip to Dashboard');
    }

    /**
     * The guarantee the whole screen rests on. A clerk gets no building for
     * Storage & Backups, because Navigation gives them no link to it.
     */
    public function test_it_only_draws_buildings_the_user_could_actually_open(): void
    {
        $this->actingAs($this->staff());
        $staffIds = collect(Compound::places())->pluck('id')->all();

        $this->actingAs($this->superAdmin());
        $adminIds = collect(Compound::places())->pluck('id')->all();

        $this->assertNotContains('storage', $staffIds);
        $this->assertNotContains('users', $staffIds);
        $this->assertContains('storage', $adminIds);
        $this->assertContains('dashboard', $staffIds);
    }

    /**
     * Every door leads somewhere the sidebar would also have sent them. Compared
     * as sets of URLs rather than of names, because the URL is the part that can
     * actually be walked through.
     */
    public function test_every_door_matches_a_link_the_sidebar_would_show(): void
    {
        $this->actingAs($this->superAdmin());

        $sidebar = collect(Navigation::forCurrentUser())->pluck('url')->all();

        $doors = collect(Compound::places())
            ->where('kind', 'link')
            ->pluck('url');

        $this->assertNotEmpty($doors);

        foreach ($doors as $url) {
            $this->assertContains($url, $sidebar);
        }
    }

    /** The compound does not draw a door onto itself. */
    public function test_it_has_no_building_for_the_compound(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertNotContains('compound', collect(Compound::places())->pluck('id')->all());
    }

    /**
     * Scenery is not a link. Nothing without a URL may claim to be one, or the
     * renderer would draw an anchor with an undefined href in the keyboard route.
     */
    public function test_scenery_carries_no_url_and_every_link_does(): void
    {
        $this->actingAs($this->superAdmin());

        foreach (Compound::places() as $place) {
            if ($place['kind'] === 'link') {
                $this->assertArrayHasKey('url', $place, $place['id'].' is a link with no url');
                $this->assertNotSame('', $place['url']);
            } else {
                $this->assertArrayNotHasKey('url', $place, $place['id'].' is scenery with a url');
            }
        }
    }

    /**
     * The flagpole divides the work wing from the administrative one, so it is
     * only drawn where there is actually an administrative wing to divide off.
     */
    public function test_the_flagpole_only_appears_when_there_is_an_admin_wing(): void
    {
        $this->actingAs($this->staff());
        $this->assertNotContains('flagpole', collect(Compound::places())->pluck('id')->all());

        $this->actingAs($this->superAdmin());
        $this->assertContains('flagpole', collect(Compound::places())->pluck('id')->all());
    }

    /**
     * Every building names a sprite and every emblem names a motif, because a
     * typo in either is invisible on the server and draws a blank wall in the
     * browser. The renderer's tables are the source of truth for what exists.
     */
    public function test_every_place_names_a_sprite_and_motif_the_renderer_has(): void
    {
        $this->actingAs($this->superAdmin());

        $js = file_get_contents(resource_path('js/world.js'));

        foreach (Compound::places() as $place) {
            $this->assertMatchesRegularExpression(
                '/^\s{4}'.preg_quote($place['sprite'], '/').':\s*\{/m',
                $js,
                'world.js has no sprite named '.$place['sprite']
            );

            if (isset($place['style']['motif'])) {
                $this->assertMatchesRegularExpression(
                    '/^\s{4}'.preg_quote($place['style']['motif'], '/').'\(c, mx, my/m',
                    $js,
                    'world.js has no motif named '.$place['style']['motif']
                );
            }
        }
    }

    /**
     * The compound must be reached by a real page load.
     *
     * Its renderer is an ES module, and a module is evaluated once per document —
     * arriving through Livewire's wire:navigate would swap the markup in and
     * never run the code that draws into it. The failure is a blank stage, which
     * looks like a rendering bug and is actually a routing one, so it is worth a
     * test that fails the moment somebody adds the attribute back.
     */
    public function test_the_sidebar_link_to_the_compound_is_not_a_soft_navigation(): void
    {
        $this->actingAs($this->superAdmin());

        $nav = collect(Navigation::forCurrentUser())->keyBy('icon');

        $this->assertFalse($nav['compound']['navigate']);
        $this->assertTrue($nav['dashboard']['navigate'], 'ordinary Livewire screens should still soft-navigate');
    }

    /** And the rendered sidebar honours it. */
    public function test_the_rendered_sidebar_omits_wire_navigate_on_the_compound_link(): void
    {
        $html = $this->actingAs($this->superAdmin())->get('/dashboard')->assertOk()->getContent();

        $compoundUrl = route('compound');

        // The anchor for the compound, up to the end of its opening tag.
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote($compoundUrl, '/').'"[^>]*>/', $html);
        preg_match('/<a href="'.preg_quote($compoundUrl, '/').'"([^>]*)>/', $html, $m);

        $this->assertStringNotContainsString('wire:navigate', $m[1]);
        $this->assertStringContainsString('data-tour="compound"', $m[1]);
    }

    /** The page lists the same destinations in plain text below the drawing. */
    public function test_it_also_lists_every_destination_as_ordinary_text(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->get('/compound');

        foreach (Compound::places() as $place) {
            if ($place['kind'] === 'link') {
                // Escaped, which is the default: one of these names is
                // "Storage & Backups" and reaches the page as "Storage &amp;
                // Backups". Searching for the raw ampersand would never match.
                $response->assertSee($place['name']);
            }
        }
    }
}
