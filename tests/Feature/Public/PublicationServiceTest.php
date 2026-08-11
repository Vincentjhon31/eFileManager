<?php

namespace Tests\Feature\Public;

use App\Enums\DisclosureCategory;
use App\Enums\Permission;
use App\Exceptions\PublicationException;
use App\Models\Announcement;
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
 * The rule this whole feature rests on: publishing is never a side effect.
 *
 * Everywhere else in this system an act is reversible or at worst recorded —
 * recall a transmittal, restore a file, correct a receipt with a new entry.
 * Disclosure is the one thing that cannot be undone once the town has read it,
 * so these tests are mostly about the gap between writing something and
 * showing it to anyone.
 */
class PublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PublicationService $publication;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->publication = app(PublicationService::class);

        // The focal person: granted the publish permission directly, the way
        // the permission's own doc comment says it should be — not inherited
        // from an operational role.
        $this->officer = User::factory()->create();
        $this->officer->givePermissionTo(Permission::PublicPublish->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    public function test_saving_a_notice_does_not_publish_it(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Suspension of classes']);

        $this->assertFalse($announcement->isLive());
        $this->assertSame('Draft', $announcement->statusLabel());
    }

    public function test_publishing_puts_it_on_the_public_page(): void
    {
        $announcement = Announcement::factory()->create();

        $this->publication->publishAnnouncement($announcement, $this->officer);

        $this->assertTrue($announcement->fresh()->isLive());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'public.announcement_published',
            'auditable_id' => $announcement->id,
        ]);
    }

    public function test_an_empty_notice_cannot_be_published(): void
    {
        $announcement = Announcement::factory()->create(['body' => '   ']);

        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('no text to publish');

        $this->publication->publishAnnouncement($announcement, $this->officer);
    }

    public function test_an_already_live_notice_cannot_be_published_again(): void
    {
        $announcement = Announcement::factory()->create();
        $this->publication->publishAnnouncement($announcement, $this->officer);

        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('already');

        $this->publication->publishAnnouncement($announcement->fresh(), $this->officer);
    }

    public function test_a_notice_cannot_be_scheduled_to_expire_before_it_appears(): void
    {
        $announcement = Announcement::factory()->create(['expires_at' => now()->addHour()]);

        $this->expectException(PublicationException::class);

        $this->publication->publishAnnouncement($announcement, $this->officer, now()->addDay());
    }

    public function test_withdrawing_takes_it_off_the_page_but_keeps_the_row(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Road closure']);
        $this->publication->publishAnnouncement($announcement, $this->officer);

        $this->publication->unpublishAnnouncement($announcement->fresh(), $this->officer, 'Superseded by a later notice.');

        $announcement->refresh();
        $this->assertFalse($announcement->isLive());
        $this->assertSame('Taken down', $announcement->statusLabel());
        $this->assertNotNull($announcement->published_at, 'The publish record is not erased.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'public.announcement_withdrawn']);
    }

    public function test_republishing_a_withdrawn_notice_clears_the_withdrawal(): void
    {
        $announcement = Announcement::factory()->create();
        $this->publication->publishAnnouncement($announcement, $this->officer);
        $this->publication->unpublishAnnouncement($announcement->fresh(), $this->officer, 'Typo.');

        $this->publication->publishAnnouncement($announcement->fresh(), $this->officer);

        $announcement->refresh();
        $this->assertTrue($announcement->isLive());
        $this->assertNull($announcement->unpublished_at);
    }

    public function test_a_notice_can_be_scheduled_for_the_future(): void
    {
        $announcement = Announcement::factory()->create();

        $this->publication->publishAnnouncement($announcement, $this->officer, now()->addDay());

        $announcement->refresh();
        $this->assertSame('Scheduled', $announcement->statusLabel());
        $this->assertFalse($announcement->isLive());

        $this->assertFalse(Announcement::query()->live()->whereKey($announcement->id)->exists());
    }

    public function test_an_expired_notice_reports_its_own_status_without_being_taken_down(): void
    {
        $announcement = Announcement::factory()->create();
        $this->publication->publishAnnouncement($announcement, $this->officer, now()->subDays(3));
        $announcement->update(['expires_at' => now()->subDay()]);

        $this->assertSame('Expired', $announcement->fresh()->statusLabel());
        $this->assertFalse(Announcement::query()->live()->whereKey($announcement->id)->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | Files — nominate, then publish
    |--------------------------------------------------------------------------
    */

    private function fileInDrive(): File
    {
        Storage::fake('documents');

        $office = Department::factory()->onboarded()->create();
        $clerk = User::factory()->inDepartment($office)->create();
        $folder = app(FileStorageService::class)->rootFolderFor($office, $clerk);

        return app(FileStorageService::class)->store(
            UploadedFile::fake()->createWithContent('budget-2026.pdf', 'the budget'),
            $folder,
            $clerk,
        );
    }

    public function test_nominating_a_file_does_not_publish_it(): void
    {
        $file = $this->fileInDrive();

        $entry = $this->publication->nominate($file, [
            'title' => '2026 Annual Budget',
            'category' => DisclosureCategory::AnnualBudget,
            'fiscal_year' => 2026,
        ], $this->officer);

        $this->assertFalse($entry->isLive());
        $this->assertDatabaseHas('audit_logs', ['event' => 'public.file_nominated']);
    }

    public function test_publishing_a_nominated_file_makes_it_live(): void
    {
        $file = $this->fileInDrive();
        $entry = $this->publication->nominate($file, [
            'title' => '2026 Annual Budget', 'category' => DisclosureCategory::AnnualBudget,
        ], $this->officer);

        $this->publication->publishFile($entry, $this->officer);

        $this->assertTrue($entry->fresh()->isLive());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'public.file_published',
            'auditable_id' => $entry->id,
        ]);
    }

    /** The hash is recorded so the municipality can prove exactly what it disclosed. */
    public function test_publishing_records_the_files_hash(): void
    {
        $file = $this->fileInDrive();
        $entry = $this->publication->nominate($file, [
            'title' => 'Budget', 'category' => DisclosureCategory::AnnualBudget,
        ], $this->officer);

        $this->publication->publishFile($entry, $this->officer);

        $log = AuditLog::where('event', 'public.file_published')->firstOrFail();
        $this->assertSame($file->sha256, $log->properties['sha256']);
    }

    public function test_a_file_already_disclosed_cannot_be_nominated_twice(): void
    {
        $file = $this->fileInDrive();
        $this->publication->nominate($file, ['title' => 'A', 'category' => DisclosureCategory::Other], $this->officer);

        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('already been prepared');

        $this->publication->nominate($file, ['title' => 'B', 'category' => DisclosureCategory::Other], $this->officer);
    }

    public function test_a_trashed_file_cannot_be_nominated(): void
    {
        $file = $this->fileInDrive();
        app(FileStorageService::class)->trash($file, $this->officer);

        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('in the trash');

        $this->publication->nominate($file->fresh(), ['title' => 'A', 'category' => DisclosureCategory::Other], $this->officer);
    }

    /**
     * A file trashed after being published must not silently keep serving —
     * withdrawFile is the explicit path; this proves the passive path is closed.
     */
    public function test_a_published_files_link_stops_working_once_the_file_is_trashed(): void
    {
        $file = $this->fileInDrive();
        $entry = $this->publication->nominate($file, ['title' => 'A', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        app(FileStorageService::class)->trash($file->fresh(), $this->officer);

        $this->assertFalse(PublicFile::query()->live()->whereKey($entry->id)->exists());
    }

    public function test_withdrawing_a_file_records_how_many_times_it_was_read(): void
    {
        $file = $this->fileInDrive();
        $entry = $this->publication->nominate($file, ['title' => 'A', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        $this->publication->countDownload($entry);
        $this->publication->countDownload($entry->fresh());

        $this->publication->withdrawFile($entry->fresh(), $this->officer, 'No longer needed.');

        $log = AuditLog::where('event', 'public.file_withdrawn')->firstOrFail();
        $this->assertSame(2, $log->properties['downloads']);
    }

    /**
     * The reader is a member of the public, not an actor this system tracks.
     * The audit trail names actors; a download count must not become a covert
     * way of identifying who read something.
     */
    public function test_counting_a_download_writes_no_audit_entry(): void
    {
        $file = $this->fileInDrive();
        $entry = $this->publication->nominate($file, ['title' => 'A', 'category' => DisclosureCategory::Other], $this->officer);
        $this->publication->publishFile($entry, $this->officer);

        $before = AuditLog::count();
        $this->publication->countDownload($entry->fresh());

        $this->assertSame($before, AuditLog::count());
        $this->assertSame(1, $entry->fresh()->download_count);
    }
}
