<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Support\Tour;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_new_user_needs_the_tour_and_a_finished_one_does_not(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->needsTour());

        $user->forceFill(['tour_completed_at' => now()])->save();

        $this->assertFalse($user->fresh()->needsTour());
    }

    public function test_completing_the_tour_stamps_the_user_and_is_not_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tour/complete')
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertNotNull($user->fresh()->tour_completed_at);
        $this->assertDatabaseMissing('audit_logs', ['user_id' => $user->id]);
    }

    public function test_a_guest_cannot_complete_the_tour(): void
    {
        $this->post('/tour/complete')->assertRedirect(route('login'));
    }

    /**
     * The tour is one list, not two: an ordinary employee never gets a step
     * for a screen Navigation would not have shown them a link to anyway.
     */
    public function test_the_tour_only_includes_stops_the_user_can_actually_reach(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(RoleEnum::Staff->value);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

        $this->actingAs($staff);
        $staffIcons = collect(Tour::stepsFor())->pluck('icon')->filter()->all();

        $this->actingAs($superAdmin);
        $adminIcons = collect(Tour::stepsFor())->pluck('icon')->filter()->all();

        $this->assertNotContains('storage', $staffIcons);
        $this->assertContains('storage', $adminIcons);
        $this->assertContains('documents', $staffIcons);
    }

    public function test_the_tour_always_opens_and_closes_on_a_centred_card(): void
    {
        $this->actingAs(User::factory()->create());

        $steps = Tour::stepsFor();

        $this->assertNull($steps[0]['icon']);
        $this->assertNull(end($steps)['icon']);
    }
}
