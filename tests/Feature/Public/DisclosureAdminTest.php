<?php

namespace Tests\Feature\Public;

use App\Enums\DisclosureCategory;
use App\Enums\FolderVisibility;
use App\Enums\Permission;
use App\Livewire\Admin\Disclosures;
use App\Models\Department;
use App\Models\PublicFile;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The disclosure board, from the inside: preparing an entry from a drive file,
 * and the boundary that stops it becoming a second door into the drive.
 */
class DisclosureAdminTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    private User $clerk;

    private FileStorageService $drive;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->office = Department::factory()->onboarded()->create();
        $this->clerk = User::factory()->inDepartment($this->office)->create();
        $this->drive = app(FileStorageService::class);
    }

    private function officer(?Department $office = null): User
    {
        $user = User::factory()->inDepartment($office ?? $this->office)->create();
        $user->givePermissionTo(Permission::PublicPublish->value);

        return $user;
    }

    public function test_someone_without_the_permission_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->clerk)->get(route('admin.disclosures.index'))->assertForbidden();
    }

    public function test_a_file_from_the_drive_can_be_prepared_for_disclosure(): void
    {
        $folder = $this->drive->rootFolderFor($this->office, $this->clerk);
        $file = $this->drive->store(
            UploadedFile::fake()->createWithContent('budget.pdf', 'contents'), $folder, $this->clerk,
        );

        Livewire::actingAs($this->officer())
            ->test(Disclosures::class)
            ->call('prepare')
            ->set('file_id', $file->id)
            ->set('title', '2026 Annual Budget')
            ->set('category', DisclosureCategory::AnnualBudget->value)
            ->set('fiscal_year', 2026)
            ->call('savePreparation')
            ->assertHasNoErrors();

        $entry = PublicFile::where('file_id', $file->id)->firstOrFail();
        $this->assertFalse($entry->isLive());
        $this->assertSame('2026 Annual Budget', $entry->title);
    }

    /**
     * The candidate list is confined by the same visibility scope as the drive
     * itself. Otherwise the disclosure board would be a way to reach into an
     * office whose folders are private.
     */
    public function test_the_candidate_list_only_offers_files_the_officer_could_already_see(): void
    {
        $otherOffice = Department::factory()->onboarded()->create();
        $otherClerk = User::factory()->inDepartment($otherOffice)->create();

        $privateFolder = $this->drive->createFolder(
            $otherOffice, null, 'Confidential', FolderVisibility::Private, $otherClerk,
        );
        $this->drive->store(
            UploadedFile::fake()->createWithContent('secret.pdf', 'x'), $privateFolder, $otherClerk,
        );

        $myFolder = $this->drive->rootFolderFor($this->office, $this->clerk);
        $this->drive->store(
            UploadedFile::fake()->createWithContent('mine.pdf', 'x'), $myFolder, $this->clerk,
        );

        Livewire::actingAs($this->officer())
            ->test(Disclosures::class)
            ->call('prepare')
            ->assertSee('mine.pdf')
            ->assertDontSee('secret.pdf');
    }

    /**
     * Even if a file id were posted directly rather than picked from the
     * dropdown, the server re-checks visibility. The form is not the security
     * boundary — this is the same posture as scopedQuery()->findOrFail()
     * elsewhere in the system: the record is not merely forbidden, it is
     * invisible, so it 404s rather than validating.
     */
    public function test_a_file_outside_the_officers_visibility_cannot_be_nominated_by_posting_its_id(): void
    {
        $otherOffice = Department::factory()->onboarded()->create();
        $otherClerk = User::factory()->inDepartment($otherOffice)->create();
        $privateFolder = $this->drive->createFolder(
            $otherOffice, null, 'Confidential', FolderVisibility::Private, $otherClerk,
        );
        $hiddenFile = $this->drive->store(
            UploadedFile::fake()->createWithContent('secret.pdf', 'x'), $privateFolder, $otherClerk,
        );

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->officer())
            ->test(Disclosures::class)
            ->call('prepare')
            ->set('file_id', $hiddenFile->id)
            ->set('title', 'Sneaky')
            ->set('category', DisclosureCategory::Other->value)
            ->call('savePreparation');
    }

    public function test_publishing_a_prepared_entry_requires_confirmation(): void
    {
        $folder = $this->drive->rootFolderFor($this->office, $this->clerk);
        $file = $this->drive->store(UploadedFile::fake()->createWithContent('b.pdf', 'x'), $folder, $this->clerk);
        $entry = PublicFile::factory()->forFile($file)->create();

        $component = Livewire::actingAs($this->officer())->test(Disclosures::class);

        $component->call('confirm', 'publish', $entry->id);
        $this->assertFalse($entry->fresh()->isLive());

        $component->call('publish')->assertHasNoErrors();
        $this->assertTrue($entry->fresh()->isLive());
    }
}
