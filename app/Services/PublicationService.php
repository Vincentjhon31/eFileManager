<?php

namespace App\Services;

use App\Exceptions\PublicationException;
use App\Models\Announcement;
use App\Models\File;
use App\Models\PublicFile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Putting something on the municipality's public page, and taking it off again.
 *
 * Separate from writing it, on purpose and at some cost in convenience.
 *
 * Everywhere else in this system an act is reversible or at worst recorded: a
 * document can be recalled, a file restored from the trash, a receipt corrected
 * by a new entry. Disclosure is the one thing that cannot be undone. Once a
 * budget has been read by somebody outside the building, withdrawing it changes
 * the page and nothing else.
 *
 * So publishing is never a side effect of saving. It is its own method, its own
 * button, its own confirmation and its own audit entry, and it is refused to
 * anyone who has not been given the authority by name.
 */
class PublicationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /*
    |--------------------------------------------------------------------------
    | Notices
    |--------------------------------------------------------------------------
    */

    public function publishAnnouncement(
        Announcement $announcement,
        User $by,
        ?CarbonInterface $at = null,
    ): Announcement {
        if ($announcement->isLive()) {
            throw PublicationException::alreadyPublished($announcement->title);
        }

        if (trim($announcement->body) === '') {
            throw PublicationException::nothingToPublish();
        }

        $at ??= now();

        if ($announcement->expires_at && $at->gte($announcement->expires_at)) {
            throw PublicationException::expiresBeforeItAppears();
        }

        $announcement->forceFill([
            'published_at' => $at,
            'published_by' => $by->getKey(),
            // A notice put back up is live again, not still withdrawn.
            'unpublished_at' => null,
            'unpublished_by' => null,
        ])->save();

        $this->record(
            event: 'public.announcement_published',
            subject: $announcement,
            actor: $by,
            properties: [
                'title' => $announcement->title,
                'category' => $announcement->category->value,
                'published_at' => $at->toIso8601String(),
                'scheduled' => $at->isFuture(),
            ],
            description: sprintf(
                '%s “%s” on the public page.',
                $at->isFuture() ? 'Scheduled' : 'Published',
                $announcement->title,
            ),
        );

        return $announcement;
    }

    public function unpublishAnnouncement(Announcement $announcement, User $by, string $reason): Announcement
    {
        if ($announcement->published_at === null) {
            throw PublicationException::neverPublished();
        }

        $announcement->forceFill([
            'unpublished_at' => now(),
            'unpublished_by' => $by->getKey(),
        ])->save();

        // Withdrawal is recorded as carefully as publication. "When did the
        // municipality stop saying this" is a question that gets asked.
        $this->record(
            event: 'public.announcement_withdrawn',
            subject: $announcement,
            actor: $by,
            properties: ['title' => $announcement->title, 'reason' => $reason],
            description: "Took “{$announcement->title}” off the public page.",
        );

        return $announcement;
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    /**
     * Nominate a file for disclosure. It is not public yet.
     *
     * Two steps rather than one: this creates the entry, and publish() is what
     * actually shows it. The gap is where somebody checks that the scan is the
     * right one and legible before the town reads it.
     */
    public function nominate(File $file, array $attributes, User $by): PublicFile
    {
        if ($file->trashed()) {
            throw PublicationException::fileIsInTheTrash($file->name);
        }

        if (PublicFile::where('file_id', $file->getKey())->exists()) {
            throw PublicationException::alreadyNominated($file->name);
        }

        $entry = PublicFile::create($attributes + [
            'file_id' => $file->getKey(),
            'created_by' => $by->getKey(),
        ]);

        $this->record(
            event: 'public.file_nominated',
            subject: $entry,
            actor: $by,
            properties: ['file_id' => $file->getKey(), 'title' => $entry->title],
            description: "Prepared “{$entry->title}” for disclosure. Not yet public.",
        );

        return $entry;
    }

    public function publishFile(PublicFile $entry, User $by, ?CarbonInterface $at = null): PublicFile
    {
        if ($entry->isLive()) {
            throw PublicationException::alreadyPublished($entry->title);
        }

        $file = $entry->file;

        if (! $file || $file->trashed()) {
            throw PublicationException::fileIsInTheTrash($entry->title);
        }

        $at ??= now();

        $entry->forceFill([
            'published_at' => $at,
            'published_by' => $by->getKey(),
            'unpublished_at' => null,
            'unpublished_by' => null,
        ])->save();

        $this->record(
            event: 'public.file_published',
            subject: $entry,
            actor: $by,
            properties: [
                'title' => $entry->title,
                'category' => $entry->category->value,
                'fiscal_year' => $entry->fiscal_year,
                'file_id' => $file->getKey(),
                'sha256' => $file->sha256,
            ],
            // The hash goes on the record so the municipality can prove years
            // later exactly which bytes it disclosed.
            description: "Published “{$entry->title}” to the public disclosure board.",
        );

        return $entry;
    }

    public function withdrawFile(PublicFile $entry, User $by, string $reason): PublicFile
    {
        if ($entry->published_at === null) {
            throw PublicationException::neverPublished();
        }

        $entry->forceFill([
            'unpublished_at' => now(),
            'unpublished_by' => $by->getKey(),
        ])->save();

        $this->record(
            event: 'public.file_withdrawn',
            subject: $entry,
            actor: $by,
            properties: ['title' => $entry->title, 'reason' => $reason, 'downloads' => $entry->download_count],
            description: sprintf(
                'Withdrew “%s” from the public board after %d download(s).',
                $entry->title,
                $entry->download_count,
            ),
        );

        return $entry;
    }

    /**
     * Count a public read.
     *
     * Deliberately not written to the audit trail. That trail names actors, and
     * the reader here is a member of the public whom this system has no
     * business identifying — counting is the most it should know.
     */
    public function countDownload(PublicFile $entry): void
    {
        DB::table('public_files')->where('id', $entry->getKey())->increment('download_count');
    }

    /** @param  array<string, mixed>  $properties */
    private function record(
        string $event,
        Model $subject,
        User $actor,
        array $properties,
        string $description,
    ): void {
        $this->audit->log(
            event: $event,
            subject: $subject,
            properties: $properties,
            description: $description,
            actor: $actor,
        );
    }
}
