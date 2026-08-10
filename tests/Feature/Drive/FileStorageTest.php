<?php

namespace Tests\Feature\Drive;

use App\Enums\FolderVisibility;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Exceptions\DriveException;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Storing, versioning and destroying files.
 *
 * These tests write real bytes to a faked disk, because the whole point of this
 * layer is that the row and the file stay in step. A test that only checked the
 * database would pass while the store filled with orphans.
 */
class FileStorageTest extends TestCase
{
    use RefreshDatabase;

    private FileStorageService $drive;

    private Department $mayor;

    private User $clerk;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->drive = app(FileStorageService::class);
        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->clerk = $this->staff($this->mayor);
        $this->folder = $this->drive->rootFolderFor($this->mayor, $this->clerk);
    }

    private function staff(Department $office, RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function pdf(string $name = 'scan.pdf', string $contents = 'PDF-CONTENTS'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /*
    |--------------------------------------------------------------------------
    | Storing
    |--------------------------------------------------------------------------
    */

    public function test_an_upload_writes_the_bytes_and_records_where_they_went(): void
    {
        $file = $this->drive->store($this->pdf('memo.pdf', 'the memo'), $this->folder, $this->clerk);

        Storage::disk('documents')->assertExists($file->storage_path);

        $this->assertSame('memo.pdf', $file->original_name);
        $this->assertSame($this->mayor->id, $file->department_id);
        $this->assertSame(1, $file->version_no);
        $this->assertTrue($file->is_current);
        $this->assertSame(hash('sha256', 'the memo'), $file->sha256);
    }

    /**
     * A stored file is named with a UUID and carries no extension, so a
     * filename from a user can never influence a path and a misconfigured web
     * root has nothing in here it would agree to execute.
     */
    public function test_stored_files_are_named_with_a_uuid_and_no_extension(): void
    {
        $file = $this->drive->store($this->pdf('../../etc/passwd.pdf'), $this->folder, $this->clerk);

        $this->assertStringNotContainsString('..', $file->storage_path);
        $this->assertStringNotContainsString('passwd', $file->storage_path);
        $this->assertSame('', pathinfo($file->storage_path, PATHINFO_EXTENSION));
        $this->assertMatchesRegularExpression(
            '#^\d+/\d{4}/\d{2}/[0-9a-f-]{36}$#',
            $file->storage_path,
        );
    }

    public function test_an_upload_is_written_to_the_audit_trail(): void
    {
        $file = $this->drive->store($this->pdf(), $this->folder, $this->clerk);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file.uploaded',
            'auditable_id' => $file->id,
            'user_id' => $this->clerk->id,
        ]);
    }

    public function test_a_file_type_that_is_not_allowed_is_refused(): void
    {
        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('cannot be uploaded');

        $this->drive->store($this->pdf('shell.php', '<?php echo 1;'), $this->folder, $this->clerk);
    }

    /** SVG is XML a browser executes, which is why it is absent from the list. */
    public function test_svg_is_refused(): void
    {
        $this->expectException(DriveException::class);

        $this->drive->store($this->pdf('logo.svg', '<svg onload="alert(1)"/>'), $this->folder, $this->clerk);
    }

    public function test_a_file_over_the_limit_is_refused(): void
    {
        config(['drive.max_upload_mb' => 1]);

        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('larger than 1 MB');

        $this->drive->store(
            UploadedFile::fake()->create('huge.pdf', 2048),
            $this->folder,
            $this->clerk,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Versions
    |--------------------------------------------------------------------------
    */

    public function test_a_new_version_keeps_the_old_one(): void
    {
        $first = $this->drive->store($this->pdf('memo.pdf', 'first draft'), $this->folder, $this->clerk);
        $second = $this->drive->storeNewVersion($first, $this->pdf('memo.pdf', 'second draft'), $this->clerk);

        $this->assertSame(2, $second->version_no);
        $this->assertSame($first->version_group_id, $second->version_group_id);
        $this->assertTrue($second->is_current);
        $this->assertFalse($first->fresh()->is_current);

        // Both sets of bytes are still there. Nothing was overwritten.
        Storage::disk('documents')->assertExists($first->storage_path);
        Storage::disk('documents')->assertExists($second->storage_path);
        $this->assertNotSame($first->storage_path, $second->storage_path);
    }

    public function test_only_the_current_version_appears_in_a_listing(): void
    {
        $first = $this->drive->store($this->pdf('memo.pdf', 'first'), $this->folder, $this->clerk);
        $this->drive->storeNewVersion($first, $this->pdf('memo.pdf', 'second'), $this->clerk);

        $this->assertSame(1, File::current()->where('folder_id', $this->folder->id)->count());
        $this->assertSame(2, File::where('folder_id', $this->folder->id)->count());
    }

    /**
     * Re-uploading the same scan is a common slip by somebody unsure whether
     * the first attempt worked. Refusing it keeps the history meaningful.
     */
    public function test_an_identical_new_version_is_refused_rather_than_recorded(): void
    {
        $first = $this->drive->store($this->pdf('memo.pdf', 'same bytes'), $this->folder, $this->clerk);

        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('byte for byte');

        $this->drive->storeNewVersion($first, $this->pdf('memo.pdf', 'same bytes'), $this->clerk);
    }

    public function test_renaming_carries_across_every_version(): void
    {
        $first = $this->drive->store($this->pdf('memo.pdf', 'one'), $this->folder, $this->clerk);
        $second = $this->drive->storeNewVersion($first, $this->pdf('memo.pdf', 'two'), $this->clerk);

        $this->drive->rename($second, 'Budget hearing memorandum', $this->clerk);

        $this->assertSame('Budget hearing memorandum', $first->fresh()->name);
        $this->assertSame('Budget hearing memorandum', $second->fresh()->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Trash and destruction
    |--------------------------------------------------------------------------
    */

    public function test_deleting_moves_every_version_to_the_trash_and_keeps_the_bytes(): void
    {
        $first = $this->drive->store($this->pdf('memo.pdf', 'one'), $this->folder, $this->clerk);
        $second = $this->drive->storeNewVersion($first, $this->pdf('memo.pdf', 'two'), $this->clerk);

        $this->drive->trash($second, $this->clerk);

        $this->assertSoftDeleted('files', ['id' => $first->id]);
        $this->assertSoftDeleted('files', ['id' => $second->id]);

        // Trash is not destruction. The bytes are exactly where they were.
        Storage::disk('documents')->assertExists($first->storage_path);
        Storage::disk('documents')->assertExists($second->storage_path);
    }

    public function test_restoring_brings_back_every_version(): void
    {
        $first = $this->drive->store($this->pdf('memo.pdf', 'one'), $this->folder, $this->clerk);
        $second = $this->drive->storeNewVersion($first, $this->pdf('memo.pdf', 'two'), $this->clerk);

        $this->drive->trash($second, $this->clerk);
        $this->drive->restore(File::withTrashed()->find($second->id), $this->clerk);

        $this->assertNull($first->fresh()->deleted_at);
        $this->assertNull($second->fresh()->deleted_at);
    }

    public function test_destroying_a_file_removes_the_rows_and_the_bytes(): void
    {
        $admin = $this->staff($this->mayor, RoleEnum::SuperAdmin);
        $file = $this->drive->store($this->pdf(), $this->folder, $this->clerk);
        $path = $file->storage_path;

        $this->drive->purge($file, $admin);

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('documents')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'file.purged']);
    }

    /**
     * The one irreversible action refuses to touch anything that forms part of
     * a tracked document's record.
     */
    public function test_a_file_attached_to_a_document_cannot_be_destroyed(): void
    {
        $admin = $this->staff($this->mayor, RoleEnum::SuperAdmin);
        $file = $this->drive->store($this->pdf(), $this->folder, $this->clerk);

        $document = Document::factory()->forOffice($this->mayor)->create([
            'document_type_id' => DocumentType::factory(),
        ]);

        DB::table('document_files')->insert([
            'document_id' => $document->id,
            'file_id' => $file->id,
            'kind' => 'main',
            'attached_by' => $this->clerk->id,
            'created_at' => now(),
        ]);

        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('part of their record');

        $this->drive->purge($file->fresh(), $admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    */

    public function test_an_office_gets_its_system_folders_on_first_use(): void
    {
        $scans = $this->drive->documentScansFolderFor($this->mayor, $this->clerk);

        $this->assertTrue($scans->is_system);
        $this->assertSame(Folder::DOCUMENTS_NAME, $scans->name);
        $this->assertSame(Folder::ROOT_NAME, $scans->parent->name);

        // Asking twice does not make a second one.
        $this->drive->documentScansFolderFor($this->mayor, $this->clerk);
        $this->assertSame(2, Folder::where('department_id', $this->mayor->id)->count());
    }

    public function test_a_system_folder_cannot_be_renamed_or_removed(): void
    {
        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('maintained by the system');

        $this->drive->renameFolder($this->folder, 'Something else', $this->clerk);
    }

    public function test_two_folders_in_the_same_place_cannot_share_a_name(): void
    {
        $this->drive->createFolder($this->mayor, $this->folder, 'Ordinances', FolderVisibility::Department, $this->clerk);

        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('already something called');

        $this->drive->createFolder($this->mayor, $this->folder, 'Ordinances', FolderVisibility::Department, $this->clerk);
    }

    public function test_the_same_name_is_fine_in_a_different_folder(): void
    {
        $a = $this->drive->createFolder($this->mayor, $this->folder, '2026', FolderVisibility::Department, $this->clerk);
        $b = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $this->clerk);

        $this->drive->createFolder($this->mayor, $a, 'Drafts', FolderVisibility::Department, $this->clerk);
        $this->drive->createFolder($this->mayor, $b, 'Drafts', FolderVisibility::Department, $this->clerk);

        $this->assertSame(2, Folder::where('name', 'Drafts')->count());
    }

    public function test_a_folder_with_anything_in_it_cannot_be_deleted(): void
    {
        $folder = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $this->clerk);
        $file = $this->drive->store($this->pdf(), $folder, $this->clerk);

        try {
            $this->drive->deleteFolder($folder, $this->clerk);
            $this->fail('A folder with a file in it was deleted.');
        } catch (DriveException $e) {
            $this->assertStringContainsString('still has things in it', $e->getMessage());
        }

        // Still true once the file is only in the trash — that is the point.
        $this->drive->trash($file, $this->clerk);

        $this->expectException(DriveException::class);
        $this->drive->deleteFolder($folder->fresh(), $this->clerk);
    }

    public function test_changing_who_can_see_a_folder_is_recorded_as_a_disclosure(): void
    {
        $folder = $this->drive->createFolder($this->mayor, null, 'Templates', FolderVisibility::Department, $this->clerk);

        $this->drive->setFolderVisibility($folder, FolderVisibility::Internal, $this->clerk);

        $this->assertSame(FolderVisibility::Internal, $folder->fresh()->visibility);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'folder.visibility_changed',
            'auditable_id' => $folder->id,
        ]);
    }

    public function test_a_file_cannot_be_moved_into_another_offices_folder(): void
    {
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL']);
        $theirs = $this->drive->rootFolderFor($legal);
        $file = $this->drive->store($this->pdf(), $this->folder, $this->clerk);

        $this->expectException(DriveException::class);
        $this->expectExceptionMessage('another office');

        $this->drive->move($file, $theirs, $this->clerk);
    }

    /*
    |--------------------------------------------------------------------------
    | Integrity
    |--------------------------------------------------------------------------
    */

    public function test_a_stored_file_can_be_checked_against_its_recorded_hash(): void
    {
        $file = $this->drive->store($this->pdf('memo.pdf', 'original bytes'), $this->folder, $this->clerk);

        $this->assertTrue($this->drive->verify($file));

        // Something changed the bytes underneath us. That is what the hash is
        // for, and it is exactly the question an auditor would ask.
        Storage::disk('documents')->put($file->storage_path, 'tampered');

        $this->assertFalse($this->drive->verify($file->fresh()));
    }

    public function test_a_file_missing_from_disk_fails_verification(): void
    {
        $file = $this->drive->store($this->pdf(), $this->folder, $this->clerk);

        Storage::disk('documents')->delete($file->storage_path);

        $this->assertFalse($this->drive->verify($file));
    }

    public function test_only_a_system_administrator_may_destroy_a_file(): void
    {
        $file = $this->drive->store($this->pdf(), $this->folder, $this->clerk);

        $this->assertFalse($this->clerk->can(Permission::SettingsManage->value));
        $this->assertFalse($this->clerk->can('forceDelete', $file));
        $this->assertTrue($this->staff($this->mayor, RoleEnum::SuperAdmin)->can('forceDelete', $file));
    }
}
