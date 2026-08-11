<?php

namespace Tests\Feature\Public;

use App\Enums\DisclosureCategory;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\File;
use App\Models\PublicFile;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\PublicationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Serving a disclosed file to a stranger.
 *
 * The route takes a public_files id, never a file id — that row is the
 * decision to disclose, and there must be no path from a guessed number in the
 * drive to a public download. These tests exist to prove that shape holds.
 */
class PublicFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private PublicationService $publication;

    private User $officer;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->publication = app(PublicationService::class);
        $this->officer = User::factory()->create();
        $this->officer->givePermissionTo(Permission::PublicPublish->value);

        $this->office = Department::factory()->onboarded()->create();
    }

    private function fileWithContents(string $contents = 'the budget'): File
    {
        $clerk = User::factory()->inDepartment($this->office)->create();
        $folder = app(FileStorageService::class)->rootFolderFor($this->office, $clerk);

        return app(FileStorageService::class)->store(
            UploadedFile::fake()->createWithContent('budget.pdf', $contents),
            $folder,
            $clerk,
        );
    }

    public function test_a_published_file_downloads_with_no_account(): void
    {
        $file = $this->fileWithContents('the real budget contents');
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::AnnualBudget], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        $response = $this->get(route('public.download', $entry));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->assertSame('the real budget contents', $response->streamedContent());
    }

    public function test_a_prepared_but_unpublished_entry_cannot_be_downloaded(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::Other], $this->officer);

        $this->get(route('public.download', $entry))->assertNotFound();
    }

    public function test_a_withdrawn_entry_cannot_be_downloaded(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);
        $this->publication->withdrawFile($entry->fresh(), $this->officer, 'done');

        $this->get(route('public.download', $entry))->assertNotFound();
    }

    /**
     * The whole point of routing through public_files rather than files: a
     * file that has never been nominated has no public_files row at all, so
     * there is no id to guess.
     */
    public function test_an_ordinary_drive_file_with_no_disclosure_entry_has_no_public_route(): void
    {
        $file = $this->fileWithContents();

        $this->assertNull(PublicFile::where('file_id', $file->id)->first());

        // The only route is by public_files id; a file id is never accepted.
        $this->get('/disclosure/'.$file->id.'/download')->assertNotFound();
    }

    public function test_downloading_increments_the_count_without_authenticating_anyone(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        $this->get(route('public.download', $entry));
        $this->get(route('public.download', $entry));

        $this->assertSame(2, $entry->fresh()->download_count);
        $this->assertGuest();
    }

    public function test_downloading_writes_no_audit_entry(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        $before = AuditLog::count();
        $this->get(route('public.download', $entry));

        $this->assertSame($before, AuditLog::count());
    }

    public function test_a_disclosed_file_that_is_later_trashed_stops_downloading(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        app(FileStorageService::class)->trash($file->fresh(), $this->officer);

        $this->get(route('public.download', $entry))->assertNotFound();
    }

    public function test_the_downloaded_filename_uses_the_public_title_not_the_stored_name(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, [
            'title' => '2026 Annual Budget', 'category' => DisclosureCategory::AnnualBudget,
        ], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        $response = $this->get(route('public.download', $entry));

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('2026 Annual Budget.pdf', $disposition);
        $this->assertStringNotContainsString($file->storage_path, $disposition);
    }

    public function test_downloads_are_rate_limited(): void
    {
        $file = $this->fileWithContents();
        $entry = $this->publication->nominate($file, ['title' => 'Budget', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        for ($i = 0; $i < 60; $i++) {
            $this->get(route('public.download', $entry));
        }

        $this->get(route('public.download', $entry))->assertStatus(429);
    }
}
