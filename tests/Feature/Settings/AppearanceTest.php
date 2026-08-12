<?php

namespace Tests\Feature\Settings;

use App\Enums\Role as RoleEnum;
use App\Livewire\Settings\Appearance;
use App\Models\Department;
use App\Models\User;
use App\Support\UserPreferences;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Light or dark, tight or roomy, small type or large.
 *
 * None of this touches a Blade template: the choices ride on <html> and the
 * stylesheet reads them. So what these tests check is that the attributes
 * actually reach the page — if they do, every view follows, including views
 * written after this was built.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->office = Department::factory()->onboarded()->create(['code' => 'MO']);
    }

    /** @param array<string, mixed> $preferences */
    private function user(array $preferences = []): User
    {
        $user = User::factory()->inDepartment($this->office)->create(['preferences' => $preferences]);
        $user->assignRole(RoleEnum::Staff->value);

        return $user;
    }

    public function test_the_appearance_screen_opens(): void
    {
        $this->actingAs($this->user())->get(route('settings.appearance'))->assertOk();
    }

    public function test_choices_are_saved(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(Appearance::class)
            ->set('theme', 'dark')
            ->set('density', 'compact')
            ->set('text_size', 'large')
            ->call('save')
            ->assertHasNoErrors();

        $preferences = $user->fresh()->preferences();

        $this->assertSame('dark', $preferences->theme());
        $this->assertSame('compact', $preferences->density());
        $this->assertSame('large', $preferences->textSize());
    }

    /** The whole mechanism in one assertion: the choice reaches the page. */
    public function test_the_choices_ride_on_the_html_element(): void
    {
        $html = $this->actingAs($this->user([
            'theme' => 'dark',
            'density' => 'compact',
            'text_size' => 'large',
        ]))->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('data-theme-choice="dark"', $html);
        $this->assertStringContainsString('data-density="compact"', $html);
        $this->assertStringContainsString('data-text="large"', $html);
    }

    public function test_somebody_with_no_choice_gets_the_defaults(): void
    {
        $html = $this->actingAs($this->user())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('data-theme-choice="system"', $html);
        $this->assertStringContainsString('data-density="comfortable"', $html);
        $this->assertStringContainsString('data-text="normal"', $html);
    }

    /**
     * The resolver has to run in <head>, before the stylesheet and before the
     * first paint. Deferred to the JS bundle it would run after the page was
     * drawn, and somebody on dark would see a white flash on every navigation.
     */
    public function test_the_theme_is_resolved_before_the_stylesheet_loads(): void
    {
        $html = $this->actingAs($this->user(['theme' => 'dark']))->get(route('dashboard'))->getContent();

        $resolver = strpos($html, 'prefers-color-scheme: dark');
        $stylesheet = strpos($html, 'app.css');

        $this->assertNotFalse($resolver);
        $this->assertNotFalse($stylesheet);
        $this->assertLessThan($stylesheet, $resolver, 'The theme resolver must come before the stylesheet.');
    }

    public function test_an_unknown_theme_falls_back_rather_than_reaching_the_page(): void
    {
        $user = $this->user(['theme' => 'neon', 'density' => 'spacious', 'text_size' => 'enormous']);

        $preferences = $user->preferences();
        $defaults = UserPreferences::defaults();

        $this->assertSame($defaults['theme'], $preferences->theme());
        $this->assertSame($defaults['density'], $preferences->density());
        $this->assertSame($defaults['text_size'], $preferences->textSize());

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('neon', $html);
    }

    public function test_a_value_outside_the_offered_set_is_refused(): void
    {
        Livewire::actingAs($this->user())
            ->test(Appearance::class)
            ->set('theme', 'neon')
            ->set('density', 'airy')
            ->set('text_size', 'huge')
            ->call('save')
            ->assertHasErrors(['theme', 'density', 'text_size']);
    }

    /*
    |--------------------------------------------------------------------------
    | Applying the choice to the page
    |--------------------------------------------------------------------------
    |
    | These three settings are attributes on <html>, and a Livewire action
    | re-renders only the component that handled it — never the layout. So the
    | server has to announce the change for the page to follow it. Without this,
    | choosing Dark and pressing Save writes the database and changes nothing on
    | screen until the next full page load, which reads as a broken setting.
    |
    */

    public function test_choosing_a_theme_announces_it_to_the_page_at_once(): void
    {
        Livewire::actingAs($this->user())
            ->test(Appearance::class)
            ->set('theme', 'dark')
            ->assertDispatched('appearance-changed', theme: 'dark');
    }

    public function test_changing_density_or_text_size_announces_too(): void
    {
        $screen = Livewire::actingAs($this->user())->test(Appearance::class);

        $screen->set('density', 'compact')->assertDispatched('appearance-changed', density: 'compact');
        $screen->set('text_size', 'large')->assertDispatched('appearance-changed', text: 'large');
    }

    public function test_saving_announces_the_choice_as_well(): void
    {
        Livewire::actingAs($this->user())
            ->test(Appearance::class)
            ->set('theme', 'dark')
            ->set('density', 'compact')
            ->set('text_size', 'large')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('appearance-changed', theme: 'dark', density: 'compact', text: 'large');
    }

    /** Only a known value may reach a DOM attribute, same as the column. */
    public function test_an_unknown_value_is_never_announced_to_the_page(): void
    {
        Livewire::actingAs($this->user())
            ->test(Appearance::class)
            ->set('theme', 'neon')
            ->assertDispatched('appearance-changed', theme: UserPreferences::defaults()['theme']);
    }

    /**
     * A save with no acknowledgement reads as a save that failed.
     *
     * The layout's status banner is never re-rendered by a Livewire action, so
     * the confirmation has to come from inside the component.
     */
    public function test_saving_says_so_on_the_screen(): void
    {
        Livewire::actingAs($this->user())
            ->test(Appearance::class)
            ->set('theme', 'dark')
            ->call('save')
            ->assertSee('Appearance saved.');
    }

    /** The listener has to exist on every page, not just the settings screen. */
    public function test_every_page_listens_for_the_announcement(): void
    {
        $html = $this->actingAs($this->user())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString("addEventListener('appearance-changed'", $html);
        $this->assertStringContainsString("addEventListener('livewire:navigated'", $html);
    }

    /** Three screens write into one JSON column; none may reset another's. */
    public function test_saving_appearance_leaves_the_other_settings_alone(): void
    {
        $user = $this->user(['rows_per_page' => 100, 'digest_email' => false]);

        Livewire::actingAs($user)
            ->test(Appearance::class)
            ->set('theme', 'dark')
            ->call('save')
            ->assertHasNoErrors();

        $preferences = $user->fresh()->preferences();

        $this->assertSame('dark', $preferences->theme());
        $this->assertSame(100, $preferences->rowsPerPage());
        $this->assertFalse($preferences->wantsDigestEmail());
    }
}
