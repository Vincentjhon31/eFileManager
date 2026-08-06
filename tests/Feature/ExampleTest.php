<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The site root is the staff dashboard, not a public page — this is an
     * internal system, so an unauthenticated visitor is sent to sign in.
     * (A public portal will be added later at its own route.)
     */
    public function test_the_root_is_private_and_redirects_guests_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Good day');
    }
}
