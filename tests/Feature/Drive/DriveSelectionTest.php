<?php

namespace Tests\Feature\Drive;

use App\Enums\FolderVisibility;
use App\Enums\Role as RoleEnum;
use App\Livewire\Drive\Browser;
use App\Models\Department;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Acting on a selection.
 *
 * The selection itself lives in the browser, which means the list of things to
 * act on arrives from the client as plain strings. Everything here is about
 * that: that a key naming another office's file buys nothing, that a mixed
 * sweep reports honestly on the half that refused, and that the flags the page
 * renders to decide what to *offer* agree with the policies that decide what is
 * actually allowed.
 */
class DriveSelectionTest extends TestCase
{
    use RefreshDatabase;

    private FileStorageService $drive;

    private Department $mayor;

    private Department $legal;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->drive = app(FileStorageService::class);
        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->legal = Department::factory()->onboarded()->create(['code' => 'LEGAL', 'short_name' => 'Legal']);
        $this->clerk = $this->staff($this->mayor, RoleEnum::ReceivingClerk);
    }

    private function staff(Department $office, RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function upload(string $name = 'scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'bytes '.$name);
    }

    private function folder(string $name, ?Department $office = null, ?Folder $parent = null): Folder
    {
        $office ??= $this->mayor;

        return $this->drive->createFolder(
            $office,
            $parent,
            $name,
            FolderVisibility::Department,
            $office->is($this->mayor) ? $this->clerk : $this->staff($office),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk acts
    |--------------------------------------------------------------------------
    */

    public function test_several_files_are_trashed_in_one_act(): void
    {
        $folder = $this->folder('Ordinances');
        $one = $this->drive->store($this->upload('a.pdf'), $folder, $this->clerk);
        $two = $this->drive->store($this->upload('b.pdf'), $folder, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkTrash', ["file:{$one->id}", "file:{$two->id}"])
            ->assertHasNoErrors();

        $this->assertSoftDeleted('files', ['id' => $one->id]);
        $this->assertSoftDeleted('files', ['id' => $two->id]);
    }

    public function test_a_key_naming_another_offices_file_is_ignored(): void
    {
        $theirs = $this->folder('Opinions', $this->legal);
        $theirFile = $this->drive->store($this->upload('opinion.pdf'), $theirs, $this->staff($this->legal));

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkTrash', ["file:{$theirFile->id}"]);

        // Not merely refused — never resolved, because visibleTo() never
        // returned it. The file is untouched and nothing was said about it.
        $this->assertNotSoftDeleted('files', ['id' => $theirFile->id]);
    }

    public function test_a_malformed_key_touches_nothing(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkTrash', ['file:0', 'nonsense', 'file:abc', ['nested'], 'folder:-1', 12345]);

        $this->assertNotSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_a_sweep_that_half_works_says_so(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);

        // The folder still holds the file, so deleting it must refuse even
        // though the file in the same sweep goes.
        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkTrash', ["file:{$file->id}", "folder:{$folder->id}"])
            ->assertHasErrors('drive');

        $this->assertSoftDeleted('files', ['id' => $file->id]);
        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    }

    public function test_restoring_and_destroying_a_selection(): void
    {
        $admin = $this->staff($this->mayor, RoleEnum::SuperAdmin);
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);
        $this->drive->trash($file, $this->clerk);

        Livewire::actingAs($admin)
            ->test(Browser::class)
            ->call('switchView', 'trash')
            ->call('bulkRestore', ["file:{$file->id}"])
            ->assertHasNoErrors();

        $this->assertNotSoftDeleted('files', ['id' => $file->id]);

        $this->drive->trash($file->refresh(), $this->clerk);

        Livewire::actingAs($admin)
            ->test(Browser::class)
            ->call('switchView', 'trash')
            ->call('bulkPurge', ["file:{$file->id}"])
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_a_clerk_cannot_destroy_even_by_calling_the_method(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);
        $this->drive->trash($file, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('switchView', 'trash')
            ->call('bulkPurge', ["file:{$file->id}"])
            ->assertHasErrors('drive');

        // Still in the trash, bytes and row intact.
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Moving
    |--------------------------------------------------------------------------
    */

    public function test_a_selection_is_moved_into_another_folder(): void
    {
        $from = $this->folder('Inbox');
        $to = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $from, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkMove', ["file:{$file->id}"], $to->id)
            ->assertHasNoErrors();

        $this->assertSame($to->id, $file->refresh()->folder_id);
    }

    public function test_a_folder_is_moved_under_another_folder(): void
    {
        $parent = $this->folder('Ordinances');
        $child = $this->folder('Drafts');

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkMove', ["folder:{$child->id}"], $parent->id)
            ->assertHasNoErrors();

        $this->assertSame($parent->id, $child->refresh()->parent_id);
    }

    /**
     * The move that would cut a subtree loose from the root: still in the
     * table, reachable by no breadcrumb and listed by no folder.
     */
    public function test_a_folder_cannot_be_moved_inside_itself(): void
    {
        $outer = $this->folder('Ordinances');
        $inner = $this->folder('2026', parent: $outer);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkMove', ["folder:{$outer->id}"], $inner->id)
            ->assertHasErrors('drive');

        $this->assertNull($outer->refresh()->parent_id);
    }

    public function test_a_file_cannot_be_moved_to_the_top_level(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->set('selection', ["file:{$file->id}"])
            ->set('moveToId', 0)
            ->call('moveSelected')
            ->assertHasErrors('drive');

        $this->assertSame($folder->id, $file->refresh()->folder_id);
    }

    /**
     * A folder another office has shared is readable, so it is listed and looks
     * like somewhere a file could be dragged. Dropping on it must say why not,
     * rather than throwing the bare 403 an authorize() would.
     */
    public function test_dropping_into_a_read_only_shared_folder_is_explained_not_thrown(): void
    {
        $mine = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $mine, $this->clerk);

        $theirs = $this->folder('Shared templates', $this->legal);
        $theirs->update(['visibility' => FolderVisibility::Internal]);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkMove', ["file:{$file->id}"], $theirs->id)
            ->assertHasErrors('drive');

        $this->assertSame($mine->id, $file->refresh()->folder_id);
    }

    public function test_a_system_folder_refuses_to_be_moved(): void
    {
        $root = $this->drive->rootFolderFor($this->mayor, $this->clerk);
        $target = $this->folder('Ordinances');

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('bulkMove', ["folder:{$root->id}"], $target->id)
            ->assertHasErrors('drive');

        $this->assertNull($root->refresh()->parent_id);
    }

    /*
    |--------------------------------------------------------------------------
    | What the page offers
    |--------------------------------------------------------------------------
    */

    /**
     * The data- flags are what the selection layer reads to decide which
     * buttons to show. They are computed in one query rather than by asking the
     * policy per row, so this pins them to the policy's actual answer.
     */
    public function test_offered_flags_match_the_policy_for_a_readable_but_unwritable_folder(): void
    {
        $theirs = $this->folder('Shared opinions', $this->legal);
        $theirs->update(['visibility' => FolderVisibility::Internal]);
        $theirFile = $this->drive->store($this->upload('opinion.pdf'), $theirs, $this->staff($this->legal));

        $html = Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('openFolder', $theirs->id)
            ->html();

        // Readable, so it is listed and may be downloaded...
        $this->assertStringContainsString('data-key="file:'.$theirFile->id.'"', $html);
        $this->assertStringContainsString('data-download="1"', $html);

        // ...but nothing that would change it is offered, and the policy agrees.
        $this->assertStringContainsString('data-delete="0"', $html);
        $this->assertStringContainsString('data-move="0"', $html);
        $this->assertFalse($this->clerk->can('delete', $theirFile));
        $this->assertFalse($this->clerk->can('update', $theirFile));
    }

    /**
     * Opening a file is a link, not a script.
     *
     * It went through the selection layer once — find the row in the DOM, set a
     * state flag, let an overlay react — and every one of those steps could
     * fail silently. One did: after opening a folder, no file would open again
     * until the page was reloaded, with nothing in the console to say why.
     *
     * An anchor cannot fail that way. This pins the property that makes it
     * true: the destination is in the markup, reachable without running any of
     * our JavaScript at all.
     */
    public function test_a_file_carries_a_real_link_to_itself(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload('memo.pdf'), $folder, $this->clerk);

        $html = Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('openFolder', $folder->id)
            ->html();

        $this->assertStringContainsString(
            'href="'.route('files.preview', $file).'" data-open-link',
            $html,
            'A file must be openable by its own anchor, with no JavaScript involved.',
        );
    }

    /**
     * A folder is a link too, for the same reason a file is.
     *
     * folderId is a #[Url] property, so the address alone opens it. Both halves
     * of "get me to the thing" are now plain navigation, and neither depends on
     * the selection layer having survived the last re-render.
     */
    public function test_a_folder_carries_a_real_link_to_itself(): void
    {
        $folder = $this->folder('Ordinances');

        $html = Livewire::actingAs($this->clerk)->test(Browser::class)->html();

        $this->assertStringContainsString(
            'href="'.$folder->openUrl('office').'" wire:navigate data-open-link',
            $html,
            'A folder must be openable by its own anchor, with no JavaScript involved.',
        );
    }

    /** Opening the same folder from Shared must not drop you back into My office. */
    public function test_a_folder_link_keeps_the_view_it_was_opened_from(): void
    {
        $theirs = $this->folder('Shared opinions', $this->legal);
        $theirs->update(['visibility' => FolderVisibility::Internal]);

        $html = Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('switchView', 'shared')
            ->html();

        // e(), because the & separating the query parameters is escaped to
        // &amp; in the attribute — the raw URL would not be found.
        $this->assertStringContainsString(e($theirs->openUrl('shared')), $html);
    }

    /** A trashed file has no working route, so it must not be linked at all. */
    public function test_a_trashed_file_carries_no_link(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);
        $this->drive->trash($file, $this->clerk);

        $html = Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('switchView', 'trash')
            ->html();

        $this->assertStringNotContainsString('data-open-link', $html);
    }

    /**
     * Nothing on this page may be hidden by x-cloak.
     *
     * Livewire's morph copies attributes onto elements it finds hidden, so a
     * closed overlay gets x-cloak put back after every update — and the
     * stylesheet hides x-cloak with !important, which beats the inline style
     * x-show sets when it is later opened. An inline display:none loses that
     * fight cleanly instead.
     */
    public function test_no_overlay_on_the_drive_relies_on_x_cloak(): void
    {
        $templates = [
            'views/livewire/drive/browser.blade.php',
            // Used on nearly every screen, and hit by the same bug: after any
            // update, closed menus stopped opening until a full reload.
            'views/components/dropdown.blade.php',
        ];

        foreach ($templates as $template) {
            // Comments explain why x-cloak is not used here, so they have to
            // come out before looking for it.
            $markup = preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                file_get_contents(resource_path($template)),
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\sx-cloak[\s=>]/',
                $markup,
                "{$template} hides an overlay with x-cloak, which a Livewire morph makes permanent.",
            );
        }
    }

    public function test_the_listing_can_be_sorted_and_a_crafted_direction_is_ignored(): void
    {
        $folder = $this->folder('Ordinances');
        $this->drive->store($this->upload('b.pdf'), $folder, $this->clerk);
        $this->drive->store($this->upload('a.pdf'), $folder, $this->clerk);

        $component = Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('openFolder', $folder->id)
            ->call('sort', 'name');

        $component->assertHasNoErrors();

        // sortBy and sortDir both arrive from the address bar; neither may
        // reach the query builder unchecked.
        $component->set('sortDir', 'desc; drop table files')
            ->set('sortBy', 'no_such_column')
            ->call('openFolder', $folder->id)
            ->assertOk();
    }

    public function test_details_describe_one_item_and_refuse_a_foreign_key(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload('memo.pdf'), $folder, $this->clerk);

        $theirs = $this->folder('Opinions', $this->legal);
        $theirFile = $this->drive->store($this->upload('secret.pdf'), $theirs, $this->staff($this->legal));

        $component = Livewire::actingAs($this->clerk)->test(Browser::class);

        $component->call('loadDetails', "file:{$file->id}")
            ->assertSee('memo.pdf')
            ->assertSee('Checksum');

        // Visible-to scoping again: the pane resolves nothing it may not read.
        $component->call('loadDetails', "file:{$theirFile->id}")
            ->assertDontSee('secret.pdf');

        $component->call('loadDetails', 'file:not-a-number')->assertHasNoErrors();
    }

    /*
    |--------------------------------------------------------------------------
    | Downloading a selection
    |--------------------------------------------------------------------------
    */

    public function test_a_bundle_zips_what_may_be_read_and_leaves_out_what_may_not(): void
    {
        $folder = $this->folder('Ordinances');
        $mine = $this->drive->store($this->upload('mine.pdf'), $folder, $this->clerk);

        $theirs = $this->folder('Opinions', $this->legal);
        $theirFile = $this->drive->store($this->upload('theirs.pdf'), $theirs, $this->staff($this->legal));

        $response = $this->actingAs($this->clerk)
            ->get(route('files.bundle', ['ids' => [$mine->id, $theirFile->id]]));

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));

        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();

        $this->assertContains('mine.pdf', $names);
        $this->assertNotContains('theirs.pdf', $names);
    }

    public function test_every_file_in_a_bundle_is_written_to_the_audit_trail(): void
    {
        $folder = $this->folder('Ordinances');
        $one = $this->drive->store($this->upload('one.pdf'), $folder, $this->clerk);
        $two = $this->drive->store($this->upload('two.pdf'), $folder, $this->clerk);

        $this->actingAs($this->clerk)
            ->get(route('files.bundle', ['ids' => [$one->id, $two->id]]))
            ->assertOk();

        foreach ([$one, $two] as $file) {
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'file.downloaded',
                'auditable_id' => $file->id,
                'user_id' => $this->clerk->id,
            ]);
        }
    }

    public function test_a_bundle_of_nothing_readable_is_refused(): void
    {
        $theirs = $this->folder('Opinions', $this->legal);
        $theirFile = $this->drive->store($this->upload(), $theirs, $this->staff($this->legal));

        $this->actingAs($this->clerk)
            ->get(route('files.bundle', ['ids' => [$theirFile->id]]))
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_cannot_bundle(): void
    {
        $folder = $this->folder('Ordinances');
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);

        $this->get(route('files.bundle', ['ids' => [$file->id]]))
            ->assertRedirect(route('login'));
    }
}
