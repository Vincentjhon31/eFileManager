<?php

namespace App\Http\Controllers;

use App\Models\PublicFile;
use App\Services\PublicationService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving a disclosed file to somebody who has not signed in.
 *
 * The most dangerous route in the system, and the shape of it is the defence:
 * it takes a **public_files** id, never a file id. That row is the decision to
 * disclose. There is no parameter here a stranger could guess their way from a
 * file in the drive to the public web, and withdrawing a disclosure is one
 * column rather than a hunt for links that have escaped.
 *
 * Note what this deliberately does not do. It writes no audit entry, because
 * that trail names actors and the reader is a member of the public this system
 * has no business identifying. It counts the download and nothing else.
 */
class PublicFileController extends Controller
{
    public function __construct(private readonly PublicationService $publication) {}

    public function download(PublicFile $publicFile): StreamedResponse
    {
        // Re-asked through the scope rather than trusted from the bound model,
        // so the one condition that matters is expressed in exactly one place.
        abort_unless(
            PublicFile::query()->live()->whereKey($publicFile->getKey())->exists(),
            404,
        );

        $file = $publicFile->file;
        $disk = Storage::disk('documents');

        // A published link that stops resolving is worse than one withdrawn on
        // purpose: it looks like the municipality quietly took something down.
        // Fail as a plain 404 to the citizen, loudly in the log for MIS.
        if (! $file || ! $disk->exists($file->storage_path)) {
            report(new \RuntimeException(
                "Disclosed file #{$publicFile->id} (“{$publicFile->title}”) is missing from storage."
            ));

            abort(404);
        }

        $this->publication->countDownload($publicFile);

        return $disk->response(
            $file->storage_path,
            $this->filename($publicFile, $file->extension()),
            [
                'Content-Type' => $file->mime,
                'X-Content-Type-Options' => 'nosniff',
                // Disclosures change rarely and are read by many. Let a proxy
                // hold them briefly, but not so long that a withdrawal takes
                // days to take effect.
                'Cache-Control' => 'public, max-age=300',
            ],
            // Always an attachment. The public title is not the stored name and
            // nothing here is worth rendering inline in a stranger's browser.
            'attachment',
        );
    }

    /** The public title, made safe for a filename. */
    private function filename(PublicFile $publicFile, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9 _.-]/u', '', $publicFile->title) ?: 'disclosure';
        $base = trim(mb_substr($base, 0, 120)) ?: 'disclosure';

        return $extension ? "{$base}.{$extension}" : $base;
    }
}
