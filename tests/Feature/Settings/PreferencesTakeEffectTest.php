<?php

namespace Tests\Feature\Settings;

use App\Enums\FolderVisibility;
use App\Enums\Role as RoleEnum;
use App\Livewire\Alerts;
use App\Livewire\Drive\Browser;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Notifications\DeskDigest;
use App\Services\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * That the preferences do something.
 *
 * A settings screen whose switches save a value and change nothing is worse
 * than no settings screen: it is a promise the system does not keep. Every
 * preference offered has a test here proving it reaches the thing it claims to
 * govern — the listings, the date on every screen, what the drive opens as,
 * where signing in puts you, and what the digest sends.
 */
class PreferencesTakeEffectTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->office = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
    }

    /** @param array<string, mixed> $preferences */
    private function user(array $preferences = [], RoleEnum $role = RoleEnum::ReceivingClerk): User
    {
        $user = User::factory()->inDepartment($this->office)->create(['preferences' => $preferences]);
        $user->assignRole($role->value);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Rows per page
    |--------------------------------------------------------------------------
    */

    public function test_rows_per_page_reaches_a_listing(): void
    {
        $reader = $this->user(['rows_per_page' => 10]);

        foreach (range(1, 14) as $i) {
            $reader->notify(new DeskDigest(mine: [], overdue: [], incoming: $i, awaiting: 0, includesOfficeSummary: true));
        }

        $alerts = Livewire::actingAs($reader)->test(Alerts::class)->viewData('alerts');

        $this->assertSame(10, $alerts->perPage());
        $this->assertCount(10, $alerts->items());
    }

    public function test_a_different_reader_gets_their_own_page_size(): void
    {
        $reader = $this->user(['rows_per_page' => 100]);

        $alerts = Livewire::actingAs($reader)->test(Alerts::class)->viewData('alerts');

        $this->assertSame(100, $alerts->perPage());
    }

    /*
    |--------------------------------------------------------------------------
    | Date and time
    |--------------------------------------------------------------------------
    */

    public function test_the_date_format_reaches_every_screen_through_the_helper(): void
    {
        // Built in Philippine time and stored as the UTC the database would
        // hold, which is the round trip these helpers exist to make correct.
        $moment = Carbon::parse('2026-08-06 08:30', ph_tz())->utc();

        $this->actingAs($this->user(['date_format' => 'Y-m-d', 'time_format' => 'H:i']));
        $this->assertSame('2026-08-06', ph_date($moment));
        $this->assertSame('2026-08-06, 08:30', ph_datetime($moment));

        $this->actingAs($this->user(['date_format' => 'd M Y', 'time_format' => 'g:i A']));
        $this->assertSame('06 Aug 2026', ph_date($moment));
        $this->assertSame('06 Aug 2026, 8:30 AM', ph_datetime($moment));
    }

    /**
     * The helpers are called from the console too — the digest, in particular —
     * where there is nobody signed in to have a preference.
     */
    public function test_the_helpers_still_work_with_nobody_signed_in(): void
    {
        $moment = Carbon::parse('2026-08-06 08:30', ph_tz())->utc();

        $this->assertSame('06 Aug 2026', ph_date($moment));
        $this->assertSame('06 Aug 2026, 8:30 AM', ph_datetime($moment));
    }

    /** An explicit format still wins: a printed slip's shape is not a taste. */
    public function test_an_explicit_format_overrides_the_preference(): void
    {
        $this->actingAs($this->user(['date_format' => 'Y-m-d']));

        $this->assertSame('06 August 2026', ph_date(now()->setDate(2026, 8, 6), 'd F Y'));
    }

    /*
    |--------------------------------------------------------------------------
    | Drive
    |--------------------------------------------------------------------------
    */

    public function test_the_drive_opens_the_way_it_was_asked_to(): void
    {
        $user = $this->user([
            'drive_view' => 'list',
            'drive_sort' => 'updated_at',
            'drive_sort_dir' => 'desc',
        ]);

        $drive = Livewire::actingAs($user)->test(Browser::class);

        $drive->assertSet('displayMode', 'list')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDir', 'desc');
    }

    public function test_the_drive_defaults_to_grid_by_name_for_somebody_with_no_preference(): void
    {
        $drive = Livewire::actingAs($this->user())->test(Browser::class);

        $drive->assertSet('displayMode', 'grid')
            ->assertSet('sortBy', 'name')
            ->assertSet('sortDir', 'asc');
    }

    /**
     * A link somebody was sent has to take them where it says it does, so the
     * address bar wins over a standing preference.
     */
    public function test_a_link_that_names_a_view_beats_the_preference(): void
    {
        $user = $this->user(['drive_view' => 'list']);

        $this->actingAs($user);

        Livewire::withQueryParams(['displayMode' => 'grid'])
            ->test(Browser::class)
            ->assertSet('displayMode', 'grid');
    }

    public function test_rows_per_page_reaches_the_drive_listing(): void
    {
        $user = $this->user(['rows_per_page' => 10]);
        $drive = app(FileStorageService::class);

        $folder = $drive->createFolder($this->office, null, 'Ordinances', FolderVisibility::Department, $user);

        foreach (range(1, 12) as $i) {
            $drive->store(UploadedFile::fake()->createWithContent("scan-{$i}.pdf", "bytes {$i}"), $folder, $user);
        }

        $files = Livewire::actingAs($user)
            ->test(Browser::class)
            ->call('openFolder', $folder->id)
            ->viewData('files');

        $this->assertSame(10, $files->perPage());
    }

    /*
    |--------------------------------------------------------------------------
    | Landing page
    |--------------------------------------------------------------------------
    */

    public function test_signing_in_lands_on_the_chosen_page(): void
    {
        $user = $this->user(['landing' => 'drive']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('drive'));
    }

    /**
     * A clerk who chose My Desk and later moved to a role that cannot reach it
     * would otherwise meet a 403 on every sign-in.
     */
    public function test_a_landing_page_the_user_cannot_reach_falls_back_to_the_dashboard(): void
    {
        $user = $this->user(['landing' => 'desk'], RoleEnum::Staff);
        $user->revokePermissionTo('documents.view.own_department');
        $user->syncRoles([]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    /** Following a link and being asked to sign in must still end at the link. */
    public function test_an_intended_destination_beats_the_landing_preference(): void
    {
        $user = $this->user(['landing' => 'drive']);

        $this->get(route('workspace'))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('workspace'));
    }

    /*
    |--------------------------------------------------------------------------
    | The digest
    |--------------------------------------------------------------------------
    */

    public function test_turning_the_digest_off_stops_it_for_that_person_only(): void
    {
        Notification::fake();

        $quiet = $this->user(['digest_email' => false]);
        $other = $this->user();
        $type = DocumentType::factory()->create();

        foreach ([$quiet, $other] as $holder) {
            Document::factory()->forOffice($this->office)->heldBy($this->office, $holder)->create([
                'document_type_id' => $type->id,
                'due_at' => now()->subDay(),
            ]);
        }

        $this->artisan('documents:send-desk-digests')->assertSuccessful();

        Notification::assertNothingSentTo($quiet);
        Notification::assertSentTo($other, DeskDigest::class);
    }

    public function test_declining_the_office_summary_shortens_the_digest(): void
    {
        Notification::fake();

        $clerk = $this->user(['digest_office_summary' => false]);
        $type = DocumentType::factory()->create();

        Document::factory()->forOffice($this->office)->heldBy($this->office, $clerk)->create([
            'document_type_id' => $type->id,
            'due_at' => now()->subDay(),
        ]);

        $this->artisan('documents:send-desk-digests')->assertSuccessful();

        Notification::assertSentTo($clerk, DeskDigest::class,
            fn (DeskDigest $digest) => $digest->includesOfficeSummary === false);
    }

    public function test_switching_the_digest_off_for_the_municipality_stops_every_message(): void
    {
        Notification::fake();
        config(['digest.enabled' => false]);

        $clerk = $this->user();
        $type = DocumentType::factory()->create();

        Document::factory()->forOffice($this->office)->heldBy($this->office, $clerk)->create([
            'document_type_id' => $type->id,
            'due_at' => now()->subDay(),
        ]);

        $this->artisan('documents:send-desk-digests')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_digest_counts_ahead_by_the_configured_number_of_days(): void
    {
        Notification::fake();
        config(['digest.due_within' => 9]);

        $clerk = $this->user();
        $type = DocumentType::factory()->create();

        Document::factory()->forOffice($this->office)->heldBy($this->office, $clerk)->create([
            'document_type_id' => $type->id,
            'due_at' => now()->addDays(7),
        ]);

        $this->artisan('documents:send-desk-digests')->assertSuccessful();

        // With the shipped default of 2 days this would have said nothing.
        Notification::assertSentTo($clerk, DeskDigest::class);
    }
}
