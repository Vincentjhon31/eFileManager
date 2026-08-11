<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The site root is the public page, not the staff dashboard — this is a
     * gov.ph address, so somebody who finds it should meet the municipality's
     * notices, not a sign-in form for a system they have no account on.
     */
    public function test_the_root_is_the_public_page_for_guests_and_staff_alike(): void
    {
        $this->get('/')->assertOk()->assertSee(config('lgu.name'));

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee(config('lgu.name'));
    }

    public function test_a_signed_in_user_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Good day');
    }

    public function test_a_guest_is_sent_to_sign_in_before_reaching_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
