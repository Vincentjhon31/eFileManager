<?php

namespace Tests\Feature\Public;

use App\Enums\Role;
use App\Livewire\Admin\Town;
use App\Models\Department;
use App\Models\File;
use App\Models\Folder;
use App\Models\LandmarkPhoto;
use App\Models\User;
use App\Support\World;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The photographs behind the drawn town.
 *
 * Two things are worth asserting and they are both about the same idea: this
 * row is the capability, not the file. A landmark_photos id may be served to a
 * stranger; a file id may not, and there is no route that takes one. Everything
 * else here guards the second hazard — these are the only bytes in the system
 * sent `inline` to somebody who has not signed in, so what may be sent that way
 * is checked at the door as well as at upload.
 */
class LandmarkPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('documents');
    }

    /** A photo row with real bytes behind it, ready to be served. */
    private function photo(string $landmark = 'court', array $file = [], array $attributes = []): LandmarkPhoto
    {
        $stored = File::factory()->image()->create($file);

        Storage::disk('documents')->put($stored->storage_path, 'not really a jpeg, but bytes');

        return LandmarkPhoto::create([
            'landmark' => $landmark,
            'file_id' => $stored->getKey(),
            'caption' => 'The court after the repainting',
        ] + $attributes);
    }

    /*
    |--------------------------------------------------------------------------
    | Serving
    |--------------------------------------------------------------------------
    */

    public function test_a_photograph_is_served_to_somebody_with_no_account(): void
    {
        $photo = $this->photo();

        $this->get(route('public.photo', $photo))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Inline is the whole point — it appears in the panel rather than saving. */
    public function test_it_is_served_inline_and_may_be_cached(): void
    {
        $response = $this->get(route('public.photo', $this->photo()));

        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
    }

    /**
     * The second half of the guard at the door.
     *
     * The upload only accepts raster images, so this state should be
     * unreachable — which is exactly why it is worth a test. `inline` on
     * something a browser renders as markup is how an upload becomes stored
     * cross-site scripting, and the route refuses rather than trusting that
     * the screen upstream did its job.
     */
    public function test_it_refuses_to_serve_anything_that_is_not_a_raster_image(): void
    {
        $svg = $this->photo(file: ['mime' => 'image/svg+xml']);
        $pdf = $this->photo(file: ['mime' => 'application/pdf']);

        $this->get(route('public.photo', $svg))->assertNotFound();
        $this->get(route('public.photo', $pdf))->assertNotFound();
    }

    public function test_a_photograph_whose_file_was_trashed_is_gone_from_the_public_side(): void
    {
        $photo = $this->photo();
        $photo->file->delete();

        $this->get(route('public.photo', $photo))->assertNotFound();
    }

    public function test_a_photograph_whose_bytes_are_missing_is_a_plain_404(): void
    {
        $stored = File::factory()->image()->create();

        $photo = LandmarkPhoto::create([
            'landmark' => 'court',
            'file_id' => $stored->getKey(),
        ]);

        $this->get(route('public.photo', $photo))->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The payload
    |--------------------------------------------------------------------------
    */

    public function test_the_town_hands_each_landmark_its_own_photographs_in_order(): void
    {
        $second = $this->photo('court', attributes: ['sort_order' => 2]);
        $first = $this->photo('court', attributes: ['sort_order' => 1]);
        $elsewhere = $this->photo('beach');

        $places = collect(World::payload(0, 0)['places'])->keyBy('id');

        $this->assertSame(
            [route('public.photo', $first), route('public.photo', $second)],
            array_column($places['court']['photos'], 'url'),
        );

        $this->assertCount(1, $places['beach']['photos']);
        $this->assertSame(route('public.photo', $elsewhere), $places['beach']['photos'][0]['url']);
    }

    /** A landmark nobody has photographed still has the key, just empty. */
    public function test_every_landmark_has_a_photos_key_even_with_nothing_in_it(): void
    {
        foreach (World::payload(0, 0)['places'] as $place) {
            $this->assertArrayHasKey('photos', $place, $place['id'].' has no photos key');
            $this->assertSame([], $place['photos']);
        }
    }

    public function test_a_trashed_file_leaves_no_gap_in_the_payload(): void
    {
        $photo = $this->photo();
        $photo->file->delete();

        $places = collect(World::payload(0, 0)['places'])->keyBy('id');

        $this->assertSame([], $places['court']['photos']);
    }

    /*
    |--------------------------------------------------------------------------
    | The screen that manages them
    |--------------------------------------------------------------------------
    */

    public function test_only_somebody_who_may_change_the_system_can_manage_the_town(): void
    {
        $clerk = User::factory()->create();
        $clerk->assignRole(Role::Staff->value);

        $this->actingAs($clerk)->get(route('admin.town.index'))->assertForbidden();
    }

    public function test_an_administrator_can_open_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);

        $this->actingAs($admin)
            ->get(route('admin.town.index'))
            ->assertOk()
            ->assertSee('Covered Court');
    }

    public function test_a_guest_cannot_reach_it_at_all(): void
    {
        $this->get(route('admin.town.index'))->assertRedirect(route('login'));
    }

    /*
    |--------------------------------------------------------------------------
    | Managing them
    |--------------------------------------------------------------------------
    */

    private function administrator(): User
    {
        $user = User::factory()->create(['department_id' => Department::factory()]);
        $user->assignRole(Role::SuperAdmin->value);

        return $user;
    }

    public function test_uploading_puts_the_photograph_on_the_landmark_and_in_the_drive(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)
            ->test(Town::class)
            ->set('landmark', 'court')
            ->set('upload', UploadedFile::fake()->image('court.jpg', 800, 500))
            ->set('caption', 'After the repainting')
            ->call('add')
            ->assertHasNoErrors();

        $photo = LandmarkPhoto::sole();

        $this->assertSame('court', $photo->landmark);
        $this->assertSame('After the repainting', $photo->caption);

        // Filed in the uploader's own office, in the system folder for these.
        $this->assertSame(Folder::TOWN_NAME, $photo->file->folder->name);
        $this->assertTrue($photo->file->folder->is_system);
        $this->assertSame($admin->department_id, $photo->file->department_id);
    }

    /**
     * The first of the three checks that keep a script off the public page.
     * The route refuses to serve one; this refuses to store one.
     */
    public function test_it_will_not_take_anything_that_is_not_a_raster_image(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(Town::class)
            ->set('landmark', 'court')
            ->set('upload', UploadedFile::fake()->create('map.svg', 40, 'image/svg+xml'))
            ->call('add')
            ->assertHasErrors('upload');

        $this->assertSame(0, LandmarkPhoto::count());
    }

    public function test_the_order_can_be_changed_and_renumbers_itself(): void
    {
        $first = $this->photo('court', attributes: ['sort_order' => 0]);
        $second = $this->photo('court', attributes: ['sort_order' => 7]);

        Livewire::actingAs($this->administrator())
            ->test(Town::class)
            ->set('landmark', 'court')
            ->call('move', $second->getKey(), -1);

        $this->assertSame([$second->getKey(), $first->getKey()], LandmarkPhoto::query()
            ->forLandmark('court')->pluck('id')->all());

        // Gaps left by earlier deletions are closed up rather than preserved.
        $this->assertSame([0, 1], LandmarkPhoto::query()
            ->forLandmark('court')->pluck('sort_order')->all());
    }

    public function test_removing_takes_it_off_the_page_and_leaves_the_file_alone(): void
    {
        $photo = $this->photo();
        $fileId = $photo->file_id;

        Livewire::actingAs($this->administrator())
            ->test(Town::class)
            ->set('landmark', 'court')
            ->call('remove', $photo->getKey());

        $this->assertSame(0, LandmarkPhoto::count());
        $this->assertNotNull(File::find($fileId), 'the file should still be in the drive');
    }

    /** One landmark's screen cannot be used to reach another landmark's photo. */
    public function test_it_will_not_touch_a_photograph_belonging_to_another_landmark(): void
    {
        $beach = $this->photo('beach');

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->administrator())
            ->test(Town::class)
            ->set('landmark', 'court')
            ->call('remove', $beach->getKey());
    }
}
