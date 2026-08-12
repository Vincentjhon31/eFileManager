<?php

namespace Tests\Feature\Settings;

use App\Enums\Role as RoleEnum;
use App\Livewire\Settings\System as SystemScreen;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SystemSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The settings that apply to everybody.
 *
 * The design being tested: a stored setting replaces one config key at boot, so
 * nothing downstream knows it is settable. That is only safe if the set of keys
 * that may be written is closed — a row is allowed to change the upload ceiling
 * and nothing else, whatever is posted.
 */
class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->office = Department::factory()->onboarded()->create(['code' => 'MO']);

        $this->admin = User::factory()->inDepartment($this->office)->create();
        $this->admin->assignRole(RoleEnum::SuperAdmin->value);
    }

    private function settings(): SystemSettings
    {
        return app(SystemSettings::class);
    }

    public function test_an_administrator_changes_a_setting_and_it_takes_effect(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SystemScreen::class)
            ->set('form.drive_max_upload_mb', 120)
            ->set('form.lgu_name', 'Municipality of Bongabong')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => 'drive.max_upload_mb']);

        // The point of the whole design: config() answers with the LGU's value,
        // so every existing caller picks it up without knowing it is settable.
        $this->settings()->flush();
        $this->settings()->applyToConfig();

        $this->assertSame(120, config('drive.max_upload_mb'));
    }

    public function test_a_change_is_written_to_the_audit_trail_with_what_moved(): void
    {
        $before = config('backups.keep_per_type');

        Livewire::actingAs($this->admin)
            ->test(SystemScreen::class)
            ->set('form.backups_keep_per_type', 12)
            ->call('save')
            ->assertHasNoErrors();

        $entry = AuditLog::where('event', 'settings.updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($before, $entry->properties['backups.keep_per_type']['before']);
        $this->assertSame(12, $entry->properties['backups.keep_per_type']['after']);
    }

    /** A form posts every field every time; only what moved is worth recording. */
    public function test_saving_without_changing_anything_records_nothing(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SystemScreen::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
        $this->assertSame(0, Setting::count());
    }

    /**
     * The closed set. A row inserted by hand — or a key posted that is not on
     * the list — must not be able to reach an arbitrary config path.
     */
    public function test_only_keys_on_the_list_can_ever_reach_the_config(): void
    {
        Setting::create(['key' => 'app.key', 'value' => 'base64:tampered']);
        Setting::create(['key' => 'database.default', 'value' => 'somewhere-else']);
        Setting::create(['key' => 'drive.max_upload_mb', 'value' => 77]);

        $realKey = config('app.key');
        $realConnection = config('database.default');

        $this->settings()->flush();
        $this->settings()->applyToConfig();

        $this->assertSame($realKey, config('app.key'));
        $this->assertSame($realConnection, config('database.default'));

        // The one that is on the list still applies.
        $this->assertSame(77, config('drive.max_upload_mb'));
    }

    public function test_put_ignores_a_key_that_is_not_a_setting(): void
    {
        $this->settings()->put(
            ['app.key' => 'tampered', 'drive.max_upload_mb' => 33],
            $this->admin,
            app(AuditLogger::class),
        );

        $this->assertDatabaseMissing('settings', ['key' => 'app.key']);
        $this->assertDatabaseHas('settings', ['key' => 'drive.max_upload_mb']);
    }

    /**
     * save() authorises on its own rather than trusting that mount() did.
     *
     * A Livewire action is a fresh request against a component the browser
     * hands back; permission can have been withdrawn since the screen was
     * opened, and the payload is under the caller's control either way. This
     * mounts legitimately, takes the permission away, and then acts.
     */
    public function test_saving_is_refused_once_the_permission_is_withdrawn(): void
    {
        $screen = Livewire::actingAs($this->admin)->test(SystemScreen::class);

        $this->admin->removeRole(RoleEnum::SuperAdmin->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $screen->set('form.drive_max_upload_mb', 99)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Setting::count());
    }

    public function test_a_setting_outside_its_range_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SystemScreen::class)
            ->set('form.drive_max_upload_mb', 100000)
            ->set('form.session_lifetime', 0)
            ->call('save')
            ->assertHasErrors(['form.drive_max_upload_mb', 'form.session_lifetime']);

        $this->assertSame(0, Setting::count());
    }

    public function test_a_digest_time_must_be_a_time(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SystemScreen::class)
            ->set('form.digest_time', 'half past seven')
            ->call('save')
            ->assertHasErrors('form.digest_time');
    }

    /**
     * Boot has to survive a database that is not there yet: `migrate` on a
     * fresh install runs through the provider before this table exists.
     */
    public function test_applying_settings_survives_a_missing_table(): void
    {
        Config::set('drive.max_upload_mb', 50);

        Schema::drop('settings');

        $this->settings()->flush();
        $this->settings()->applyToConfig();

        // No exception, and the config file's answer stands.
        $this->assertSame(50, config('drive.max_upload_mb'));
    }

    public function test_the_lgu_code_is_shown_but_not_editable(): void
    {
        $screen = Livewire::actingAs($this->admin)->test(SystemScreen::class);

        $screen->assertSee(config('lgu.code'))
            ->assertSee('Fixed for the life of the system');

        // Not a form field, so there is nothing to post.
        $this->assertArrayNotHasKey('lgu_code', $screen->get('form'));
    }
}
