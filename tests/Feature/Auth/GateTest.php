<?php

namespace Tests\Feature\Auth;

use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Support\World;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The door of the Municipal Hall.
 *
 * The rule under test is the one this system has always had and has not
 * changed: **nobody signs themselves in.** A request creates a row that cannot
 * be used until somebody in MIS has looked at it, and the assertions here are
 * mostly about that — that the row is inert, that it stays inert, and that the
 * form does not become a way to find out who works for the municipality.
 */
class GateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** The same office however many times it is asked for. */
    private function office(): Department
    {
        return Department::firstOrCreate(
            ['code' => 'MO'],
            [
                'name' => 'Office of the Municipal Mayor',
                'short_name' => "Mayor's Office",
                'is_external' => false,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The three doors
    |--------------------------------------------------------------------------
    */

    public function test_the_hall_leads_to_the_door_rather_than_to_a_password_box(): void
    {
        $hall = collect(World::publicPlaces(0, 0))->firstWhere('id', 'hall');

        $this->assertSame(route('public.enter'), $hall['url']);
    }

    /**
     * The hall is a door, and doors open.
     *
     * Every other landmark shows a photograph of itself first, because every
     * other landmark is somewhere you might want to look at. Putting a picture
     * of the building between somebody and the thing they clicked the building
     * to do is a step to get past, not a feature.
     */
    public function test_the_hall_goes_straight_through_and_nothing_else_does(): void
    {
        foreach (World::publicPlaces(0, 0) as $place) {
            $straight = $place['straight'] ?? false;

            if ($place['id'] === 'hall') {
                $this->assertTrue($straight, 'the hall should not stop to show a photograph');
                $this->assertArrayHasKey('url', $place, 'a landmark that goes straight through needs somewhere to go');
            } else {
                $this->assertFalse($straight, $place['id'].' should open its panel like everywhere else');
            }
        }
    }

    /**
     * Coming back to a page that was covered when it left.
     *
     * Leaving through the front door closes a cloud wipe over the town, and the
     * browser's back/forward cache freezes the document exactly as it was —
     * wipe and all — so going back restored a blank cream screen that only a
     * refresh would clear. pageshow is the one event that fires on a thaw, and
     * the renderer must be listening for it.
     *
     * Asserted against the source in the same spirit as the sprite and motif
     * checks: the failure is invisible on the server and total in the browser.
     */
    public function test_the_wipe_is_taken_off_when_a_frozen_page_is_restored(): void
    {
        $js = file_get_contents(resource_path('js/world/chrome.js'));

        $this->assertMatchesRegularExpression(
            "/addEventListener\(\s*'pageshow'/",
            $js,
            'the cloud wipe must clear itself when the page comes back out of the cache',
        );
    }

    public function test_a_guest_is_offered_all_three_doors(): void
    {
        $this->get(route('public.enter'))
            ->assertOk()
            ->assertSee("I'm just visiting", false)
            ->assertSee('Sign in')
            ->assertSee('Request an account');
    }

    public function test_somebody_signed_in_is_asked_where_to_go_instead(): void
    {
        $user = User::factory()->create(['department_id' => $this->office()->getKey()]);
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)
            ->get(route('public.enter'))
            ->assertOk()
            ->assertSee('Take me to my office')
            ->assertSee("Mayor's Office")
            ->assertDontSee('Request an account');
    }

    public function test_choosing_to_visit_opens_the_compound_and_creates_no_account(): void
    {
        $before = User::count();

        $this->post(route('public.visitor'))
            ->assertRedirect(route('compound'))
            ->assertSessionHas('visitor', true);

        $this->assertSame($before, User::count());
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Requesting an account
    |--------------------------------------------------------------------------
    */

    private function validRequest(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juana dela Cruz',
            'email' => 'juana@bongabong.gov.ph',
            'employee_no' => 'E-2026-0001',
            'department_id' => $this->office()->getKey(),
            'position' => 'Administrative Aide IV',
            'phone' => '0917 000 0000',
        ], $overrides);
    }

    public function test_the_form_names_the_offices_somebody_could_work_in(): void
    {
        $this->office();
        Department::factory()->create(['code' => 'EXT-COA', 'name' => 'Commission on Audit', 'is_external' => true]);

        $this->get(route('account.request'))
            ->assertOk()
            ->assertSee("Mayor's Office")
            ->assertDontSee('Commission on Audit');
    }

    public function test_a_request_creates_an_account_that_cannot_be_used(): void
    {
        $this->post(route('account.request.store'), $this->validRequest())
            ->assertRedirect(route('public.enter'))
            ->assertSessionHas('status');

        $user = User::where('email', 'juana@bongabong.gov.ph')->sole();

        $this->assertFalse($user->is_active);
        $this->assertFalse($user->canSignIn());
        $this->assertCount(0, $user->roles, 'a requested account must have no role at all');
        $this->assertNotNull($user->department_id);
    }

    /** The whole point: the row exists and the door stays shut. */
    public function test_a_requested_account_is_refused_at_sign_in(): void
    {
        $this->post(route('account.request.store'), $this->validRequest());

        $user = User::where('email', 'juana@bongabong.gov.ph')->sole();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_the_request_is_written_to_the_audit_trail(): void
    {
        $this->post(route('account.request.store'), $this->validRequest());

        $this->assertTrue(
            AuditLog::query()->where('event', 'account.requested')->exists(),
            'asking for an account should leave a trail',
        );
    }

    /**
     * A form that says "that address already has an account" on a government
     * domain is a way to find out who works there.
     */
    public function test_it_does_not_say_whether_an_address_is_already_known(): void
    {
        $office = $this->office();
        User::factory()->create(['email' => 'taken@bongabong.gov.ph']);

        $fresh = $this->post(route('account.request.store'), $this->validRequest([
            'department_id' => $office->getKey(),
        ]));

        $duplicate = $this->post(route('account.request.store'), $this->validRequest([
            'email' => 'taken@bongabong.gov.ph',
            'employee_no' => 'E-2026-0002',
            'department_id' => $office->getKey(),
        ]));

        $this->assertSame($fresh->headers->get('Location'), $duplicate->headers->get('Location'));
        $this->assertSame(
            session()->get('status'),
            $duplicate->getSession()->get('status'),
        );

        // And nothing was written for the address that was already taken.
        $this->assertSame(1, User::where('email', 'taken@bongabong.gov.ph')->count());
    }

    public function test_an_external_party_is_not_an_office_anybody_works_in(): void
    {
        $external = Department::factory()->create(['code' => 'EXT-COA', 'is_external' => true]);

        $this->post(route('account.request.store'), $this->validRequest([
            'department_id' => $external->getKey(),
        ]))->assertSessionHasErrors('department_id');

        $this->assertSame(0, User::where('email', 'juana@bongabong.gov.ph')->count());
    }

    public function test_the_office_is_required(): void
    {
        $this->post(route('account.request.store'), $this->validRequest(['department_id' => null]))
            ->assertSessionHasErrors('department_id');
    }

    public function test_somebody_already_signed_in_is_not_offered_the_form(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Staff->value);

        $this->actingAs($user)->get(route('account.request'))->assertRedirect();
    }
}
