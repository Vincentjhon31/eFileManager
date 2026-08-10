<?php

namespace Tests\Feature\Drive;

use App\Enums\ActionRequested;
use App\Enums\FolderVisibility;
use App\Enums\Role as RoleEnum;
use App\Livewire\Documents\Attachments;
use App\Livewire\Drive\Browser;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\DocumentRoutingService;
use App\Services\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens: browsing, uploading, and attaching a scan to a tracked document.
 */
class DriveBrowserTest extends TestCase
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

    private function upload(string $name = 'scan.pdf', string $contents = 'bytes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /*
    |--------------------------------------------------------------------------
    | Browsing
    |--------------------------------------------------------------------------
    */

    public function test_opening_the_drive_gives_the_office_its_folders(): void
    {
        $this->actingAs($this->clerk)->get(route('drive'))->assertOk();

        $this->assertDatabaseHas('folders', [
            'department_id' => $this->mayor->id,
            'name' => Folder::ROOT_NAME,
            'is_system' => true,
        ]);
    }

    public function test_a_folder_is_created_and_then_holds_an_upload(): void
    {
        $component = Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('open', 'new-folder')
            ->set('formName', 'Ordinances 2026')
            ->set('formVisibility', FolderVisibility::Department->value)
            ->call('createFolder')
            ->assertHasNoErrors();

        $folder = Folder::where('name', 'Ordinances 2026')->firstOrFail();

        $component->call('openFolder', $folder->id)
            ->set('upload', $this->upload('ordinance-41.pdf'))
            ->call('uploadFile')
            ->assertHasNoErrors()
            ->assertSee('ordinance-41.pdf');

        $this->assertSame(1, File::where('folder_id', $folder->id)->count());
    }

    public function test_an_upload_needs_a_folder_to_go_in(): void
    {
        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->set('upload', $this->upload())
            ->call('uploadFile')
            ->assertHasErrors('upload');
    }

    public function test_a_refusal_from_the_drive_is_shown_as_a_readable_message(): void
    {
        $folder = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('open', 'new-folder')
            ->set('formName', 'Ordinances')
            ->call('createFolder')
            ->assertHasErrors('drive')
            ->assertSee('already something called');

        $this->assertSame(1, Folder::where('name', 'Ordinances')->count());
    }

    public function test_another_offices_files_never_appear_in_a_search(): void
    {
        $theirs = $this->drive->createFolder($this->legal, null, 'Opinions', FolderVisibility::Department, $this->staff($this->legal));
        $this->drive->store($this->upload('confidential-opinion.pdf'), $theirs, $this->staff($this->legal));

        $mine = $this->drive->createFolder($this->mayor, null, 'Mine', FolderVisibility::Department, $this->clerk);
        $this->drive->store($this->upload('my-opinion.pdf'), $mine, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->set('search', 'opinion')
            ->assertSee('my-opinion.pdf')
            ->assertDontSee('confidential-opinion.pdf');
    }

    public function test_the_shared_view_lists_what_other_offices_have_opened_up(): void
    {
        $this->drive->createFolder($this->legal, null, 'Contract templates', FolderVisibility::Internal, $this->staff($this->legal));
        $this->drive->createFolder($this->legal, null, 'Legal opinions', FolderVisibility::Department, $this->staff($this->legal));

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('switchView', 'shared')
            ->assertSee('Contract templates')
            ->assertDontSee('Legal opinions');
    }

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    */

    public function test_a_deleted_file_moves_to_the_trash_and_comes_back(): void
    {
        $folder = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $this->clerk);
        $file = $this->drive->store($this->upload('ordinance-41.pdf'), $folder, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('openFolder', $folder->id)
            ->call('trashFile', $file->id)
            ->assertHasNoErrors()
            ->assertDontSee('ordinance-41.pdf')
            ->call('switchView', 'trash')
            ->assertSee('ordinance-41.pdf')
            ->call('restoreFile', $file->id)
            ->assertDontSee('ordinance-41.pdf');

        $this->assertNull($file->fresh()->deleted_at);
    }

    public function test_an_ordinary_clerk_is_not_offered_permanent_destruction(): void
    {
        $folder = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Department, $this->clerk);
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);
        $this->drive->trash($file, $this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Browser::class)
            ->call('switchView', 'trash')
            ->assertSee('Restore')
            ->assertDontSee('Destroy');
    }

    public function test_a_clerk_from_another_office_cannot_touch_our_files(): void
    {
        $folder = $this->drive->createFolder($this->mayor, null, 'Ordinances', FolderVisibility::Internal, $this->clerk);
        $file = $this->drive->store($this->upload(), $folder, $this->clerk);

        Livewire::actingAs($this->staff($this->legal))
            ->test(Browser::class)
            ->call('trashFile', $file->id)
            ->assertForbidden();

        $this->assertNull($file->fresh()->deleted_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Attaching to a tracked document
    |--------------------------------------------------------------------------
    */

    private function registeredDocument(User $by): Document
    {
        return app(DocumentRoutingService::class)->register([
            'document_type_id' => DocumentType::factory()->create()->id,
            'subject' => 'Budget hearing schedule',
            'origin_department_id' => $by->department_id,
        ], $by);
    }

    public function test_a_scan_attached_to_a_document_lands_in_the_office_folder(): void
    {
        $document = $this->registeredDocument($this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Attachments::class, ['document' => $document])
            ->set('upload', $this->upload('letter-scan.pdf'))
            ->set('kind', 'main')
            ->call('attach')
            ->assertHasNoErrors()
            ->assertSee('letter-scan.pdf');

        $file = File::where('original_name', 'letter-scan.pdf')->firstOrFail();

        // An ordinary drive file, in the office's system folder — not a second
        // store with its own rules.
        $this->assertSame(Folder::DOCUMENTS_NAME, $file->folder->name);
        $this->assertTrue($file->folder->is_system);

        $this->assertDatabaseHas('document_files', [
            'document_id' => $document->id,
            'file_id' => $file->id,
            'kind' => 'main',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'document.file_attached',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_a_document_has_only_one_main_copy(): void
    {
        $document = $this->registeredDocument($this->clerk);

        $component = Livewire::actingAs($this->clerk)->test(Attachments::class, ['document' => $document]);

        foreach (['first.pdf', 'second.pdf'] as $name) {
            $component->set('upload', $this->upload($name))->set('kind', 'main')->call('attach')->assertHasNoErrors();
        }

        $kinds = DB::table('document_files')
            ->where('document_id', $document->id)->pluck('kind')->sort()->values()->all();

        $this->assertSame(['attachment', 'main'], $kinds);
    }

    /** Detaching is not deleting: the scan stays in the office's drive. */
    public function test_detaching_leaves_the_file_in_the_drive(): void
    {
        $document = $this->registeredDocument($this->clerk);

        $component = Livewire::actingAs($this->clerk)
            ->test(Attachments::class, ['document' => $document])
            ->set('upload', $this->upload('letter-scan.pdf'))
            ->call('attach');

        $file = File::where('original_name', 'letter-scan.pdf')->firstOrFail();

        $component->call('detach', $file->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('document_files', ['document_id' => $document->id, 'file_id' => $file->id]);
        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
        Storage::disk('documents')->assertExists($file->storage_path);
    }

    public function test_an_office_that_is_not_holding_a_document_cannot_attach_to_it(): void
    {
        $document = $this->registeredDocument($this->clerk);
        $outsider = $this->staff($this->legal, RoleEnum::SuperAdmin);

        // A superadmin can see the document, but attaching is part of working
        // on it, and only the holding office does that.
        Livewire::actingAs($outsider)
            ->test(Attachments::class, ['document' => $document])
            ->set('upload', $this->upload())
            ->call('attach')
            ->assertForbidden();
    }

    public function test_an_attachment_is_readable_by_an_office_the_document_reaches(): void
    {
        $document = $this->registeredDocument($this->clerk);

        Livewire::actingAs($this->clerk)
            ->test(Attachments::class, ['document' => $document])
            ->set('upload', $this->upload('letter-scan.pdf'))
            ->call('attach');

        $file = File::where('original_name', 'letter-scan.pdf')->firstOrFail();
        $legalStaff = $this->staff($this->legal);

        // Before it is sent, Legal has no business with either.
        $this->actingAs($legalStaff)->get(route('files.download', $file))->assertForbidden();

        app(DocumentRoutingService::class)->release(
            $document, $this->legal, ActionRequested::ForComment, $this->clerk,
        );

        // The scan sits in the Mayor's Office folder, which Legal cannot browse
        // — so the document reaching them does not by itself open the drive.
        // This is the boundary worth being explicit about.
        $this->actingAs($legalStaff)->get(route('files.download', $file))->assertForbidden();
    }
}
