<?php

namespace Tests\Feature\Drive;

use App\Enums\FolderVisibility;
use App\Enums\Role as RoleEnum;
use App\Models\Department;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who can read what, and how the bytes are served.
 *
 * The documents disk is outside the web root with `'serve' => false`, so there
 * is no URL that reaches a file directly. Every read comes through the
 * controller, is authorised, and is written to the audit trail — which is what
 * makes it possible to answer "who has read this personnel file".
 */
class DriveAccessTest extends TestCase
{
    use RefreshDatabase;

    private FileStorageService $drive;

    private Department $mayor;

    private Department $legal;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->drive = app(FileStorageService::class);
        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->legal = Department::factory()->onboarded()->create(['code' => 'LEGAL', 'short_name' => 'Legal']);
    }

    private function staff(Department $office, RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function fileIn(Folder $folder, User $by, string $name = 'scan.pdf'): File
    {
        return $this->drive->store(
            UploadedFile::fake()->createWithContent($name, 'contents of '.$name),
            $folder,
            $by,
        );
    }

    /** @return array<int, int> */
    private function visibleTo(User $user): array
    {
        return Folder::query()->visibleTo($user)->pluck('id')->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Folder visibility
    |--------------------------------------------------------------------------
    */

    public function test_an_office_sees_its_own_folders(): void
    {
        $clerk = $this->staff($this->mayor);
        $folder = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $clerk);

        $this->assertContains($folder->id, $this->visibleTo($clerk));
    }

    public function test_another_offices_folders_are_invisible(): void
    {
        $theirs = $this->drive->createFolder(
            $this->legal, null, 'Legal opinions', FolderVisibility::Department, $this->staff($this->legal),
        );

        $this->assertNotContains($theirs->id, $this->visibleTo($this->staff($this->mayor)));
    }

    /** "Only me" means only me — an office head is not an exception. */
    public function test_a_private_folder_is_invisible_to_colleagues_and_to_the_office_head(): void
    {
        $owner = $this->staff($this->mayor);
        $folder = $this->drive->createFolder($this->mayor, null, 'Drafts', FolderVisibility::Private, $owner);

        $this->assertContains($folder->id, $this->visibleTo($owner));
        $this->assertNotContains($folder->id, $this->visibleTo($this->staff($this->mayor)));
        $this->assertNotContains($folder->id, $this->visibleTo($this->staff($this->mayor, RoleEnum::Approver)));
        $this->assertNotContains($folder->id, $this->visibleTo($this->staff($this->mayor, RoleEnum::DepartmentAdmin)));
    }

    public function test_a_folder_shared_with_all_staff_is_readable_from_any_office(): void
    {
        $folder = $this->drive->createFolder(
            $this->legal, null, 'Contract templates', FolderVisibility::Internal, $this->staff($this->legal),
        );

        $this->assertContains($folder->id, $this->visibleTo($this->staff($this->mayor)));
    }

    /**
     * Sharing a folder makes it readable, never writable. Otherwise "shared
     * with everyone" would quietly come to mean "editable by everyone".
     */
    public function test_a_shared_folder_is_readable_but_not_writable_from_another_office(): void
    {
        $outsider = $this->staff($this->mayor);
        $folder = $this->drive->createFolder(
            $this->legal, null, 'Contract templates', FolderVisibility::Internal, $this->staff($this->legal),
        );

        $this->assertTrue($outsider->can('view', $folder));
        $this->assertFalse($outsider->can('store', $folder));
        $this->assertFalse($outsider->can('update', $folder));
        $this->assertFalse($outsider->can('delete', $folder));
    }

    public function test_a_system_administrator_sees_every_offices_folders(): void
    {
        $theirs = $this->drive->createFolder(
            $this->legal, null, 'Legal opinions', FolderVisibility::Department, $this->staff($this->legal),
        );

        $this->assertContains($theirs->id, $this->visibleTo($this->staff($this->mayor, RoleEnum::SuperAdmin)));
    }

    public function test_a_user_with_no_office_sees_nothing(): void
    {
        $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $this->staff($this->mayor));

        $stranger = User::factory()->create(['department_id' => null]);
        $stranger->assignRole(RoleEnum::Staff->value);

        $this->assertSame([], $this->visibleTo($stranger));
        $this->assertSame([], Folder::query()->visibleTo(null)->pluck('id')->all());
    }

    /** A file has no permissions of its own; it inherits its folder's. */
    public function test_a_file_is_as_visible_as_the_folder_it_sits_in(): void
    {
        $owner = $this->staff($this->legal);
        $outsider = $this->staff($this->mayor);

        $private = $this->drive->createFolder($this->legal, null, 'Drafts', FolderVisibility::Private, $owner);
        $shared = $this->drive->createFolder($this->legal, null, 'Templates', FolderVisibility::Internal, $owner);

        $hidden = $this->fileIn($private, $owner, 'draft.pdf');
        $open = $this->fileIn($shared, $owner, 'template.pdf');

        $visible = File::query()->visibleTo($outsider)->pluck('id')->all();

        $this->assertNotContains($hidden->id, $visible);
        $this->assertContains($open->id, $visible);
    }

    /*
    |--------------------------------------------------------------------------
    | Serving the bytes
    |--------------------------------------------------------------------------
    */

    public function test_a_file_downloads_for_someone_who_may_read_it(): void
    {
        $clerk = $this->staff($this->mayor);
        $file = $this->fileIn($this->drive->rootFolderFor($this->mayor, $clerk), $clerk, 'memo.pdf');

        $response = $this->actingAs($clerk)->get(route('files.download', $file));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            // Without nosniff a browser may decide for itself what the bytes
            // are, which would defeat restricting what can be shown inline.
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertSame('contents of memo.pdf', $response->streamedContent());
    }

    public function test_another_office_cannot_download_a_file_it_cannot_see(): void
    {
        $owner = $this->staff($this->legal);
        $file = $this->fileIn($this->drive->rootFolderFor($this->legal, $owner), $owner);

        $this->actingAs($this->staff($this->mayor))
            ->get(route('files.download', $file))
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $owner = $this->staff($this->mayor);
        $file = $this->fileIn($this->drive->rootFolderFor($this->mayor, $owner), $owner);

        $this->get(route('files.download', $file))->assertRedirect(route('login'));
    }

    public function test_every_download_is_written_to_the_audit_trail(): void
    {
        $clerk = $this->staff($this->mayor);
        $file = $this->fileIn($this->drive->rootFolderFor($this->mayor, $clerk), $clerk, 'personnel.pdf');

        $this->actingAs($clerk)->get(route('files.download', $file))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file.downloaded',
            'auditable_id' => $file->id,
            'user_id' => $clerk->id,
        ]);
    }

    public function test_a_pdf_can_be_shown_in_the_browser(): void
    {
        $clerk = $this->staff($this->mayor);
        $file = $this->fileIn($this->drive->rootFolderFor($this->mayor, $clerk), $clerk, 'memo.pdf');

        $response = $this->actingAs($clerk)->get(route('files.preview', $file));

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    /**
     * Content-Disposition: inline hands the file to whatever the browser does
     * with that type. For anything it renders as markup that is a stored
     * cross-site-scripting hole, so only PDFs and images are ever inlined.
     */
    public function test_a_type_the_browser_would_render_as_markup_cannot_be_inlined(): void
    {
        $clerk = $this->staff($this->mayor);

        $file = $this->drive->store(
            UploadedFile::fake()->createWithContent('notes.txt', '<script>alert(1)</script>'),
            $this->drive->rootFolderFor($this->mayor, $clerk),
            $clerk,
        );

        $this->assertFalse($file->isPreviewable());
        $this->actingAs($clerk)->get(route('files.preview', $file))->assertNotFound();

        // It still downloads, as an attachment.
        $this->actingAs($clerk)->get(route('files.download', $file))->assertOk();
    }

    public function test_a_file_in_the_trash_cannot_be_downloaded(): void
    {
        $clerk = $this->staff($this->mayor);
        $file = $this->fileIn($this->drive->rootFolderFor($this->mayor, $clerk), $clerk);

        $this->drive->trash($file, $clerk);

        $this->actingAs($clerk)->get(route('files.download', $file))->assertNotFound();
    }

    /**
     * The one configuration mistake that would undo all of the above: adding
     * the documents disk to the storage:link array, or turning on Laravel's
     * local-disk serving route.
     */
    public function test_the_document_store_is_not_web_reachable_by_configuration(): void
    {
        $this->assertFalse(config('filesystems.disks.documents.serve'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
        $this->assertNotContains(
            storage_path('app/documents'),
            array_values(config('filesystems.links', [])),
            'The documents disk must never be symlinked into the web root.',
        );
    }
}
