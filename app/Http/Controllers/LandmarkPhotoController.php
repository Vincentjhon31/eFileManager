<?php

namespace App\Http\Controllers;

use App\Models\LandmarkPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Showing a photograph of the town to somebody who has not signed in.
 *
 * The shape is the defence, exactly as in PublicFileController: this takes a
 * **landmark_photos** id, never a file id. That row is the decision to show the
 * picture, so there is no parameter here a stranger could walk from a file in
 * an office's drive to the public web, and taking a photo down is deleting one
 * row rather than hunting for links.
 *
 * Unlike a disclosure this is served `inline` — the whole point is that it
 * appears in the panel — which makes the content type the thing that matters.
 * Two guards, because inline plus a type a browser renders as markup is how an
 * upload becomes stored cross-site scripting: the upload is restricted to
 * raster images when it is taken, and this refuses to serve anything else even
 * if a row somehow names one.
 *
 * Like the disclosure route it writes no audit entry. The reader is a member of
 * the public and this system has no business identifying them.
 */
class LandmarkPhotoController extends Controller
{
    /**
     * What a browser may be handed inline from a public, unauthenticated route.
     *
     * Kept here rather than in config because it is a security boundary, not a
     * preference: every one of these is decoded by an image decoder and none of
     * them can be a document. SVG is absent on purpose — it is markup, it can
     * carry script, and config/drive.php already excludes it from uploads for
     * the same reason.
     */
    private const SERVEABLE = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function show(LandmarkPhoto $landmarkPhoto): BinaryFileResponse
    {
        // Re-asked through the scope rather than trusted from the bound model,
        // so the one condition that matters is expressed in exactly one place.
        abort_unless(
            LandmarkPhoto::query()->live()->whereKey($landmarkPhoto->getKey())->exists(),
            404,
        );

        $file = $landmarkPhoto->file;
        $disk = Storage::disk('documents');

        abort_unless($file && in_array($file->mime, self::SERVEABLE, true), 404);

        if (! $disk->exists($file->storage_path)) {
            // A picture that stops resolving leaves a broken frame on the front
            // page of a government site. Plain 404 to the visitor, loudly in
            // the log for MIS.
            report(new \RuntimeException(
                "Landmark photo #{$landmarkPhoto->id} ({$landmarkPhoto->landmark}) is missing from storage."
            ));

            abort(404);
        }

        // BinaryFileResponse for the same reason as the drive's preview route:
        // the router prepares whatever a controller returns, and this is the
        // one that answers a Range header properly and sets Last-Modified from
        // the file itself, so a revisit is a 304 rather than a re-download.
        $response = new BinaryFileResponse($disk->path($file->storage_path));

        $response->headers->set('Content-Type', $file->mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Photographs of a basketball court do not change. A day is long enough
        // to be worth having and short enough that replacing one is visible by
        // the next morning; the cache is public because there is nothing
        // personal in it and a proxy holding it is the point.
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        $response->setContentDisposition('inline', $this->filename($landmarkPhoto, $file->extension()));
        $response->setAutoLastModified();

        return $response;
    }

    /** The caption, made safe for a filename, for somebody who saves it. */
    private function filename(LandmarkPhoto $photo, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9 _.-]/u', '', (string) ($photo->caption ?: $photo->landmark));
        $base = trim(mb_substr($base ?: '', 0, 120)) ?: 'photo';

        return $extension ? "{$base}.{$extension}" : $base;
    }
}
