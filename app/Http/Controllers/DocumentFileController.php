<?php

namespace App\Http\Controllers;

use App\Exceptions\DriveException;
use App\Models\File;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * The only way to read a stored file.
 *
 * The documents disk sits outside the web root with `'serve' => false`, so
 * there is no URL that reaches the bytes directly and no symlink pointing at
 * them. Every read comes through here, is authorised first, and is written to
 * the audit trail — which is what RA 10173 asks for and what makes it possible
 * to answer "who has read this personnel file".
 *
 * Nothing in here trusts anything stored on the row except the path. The
 * content type is sent from the database with nosniff, and the filename in the
 * header is built from a name the user cannot use to smuggle a directory.
 */
class DocumentFileController extends Controller
{
    /** A selection can run to hundreds; a single zip should not. */
    private const MAX_BUNDLE = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    public function download(File $file): BinaryFileResponse
    {
        Gate::authorize('download', $file);

        return $this->stream($file, 'attachment', 'file.downloaded');
    }

    /**
     * Show it in the browser rather than saving it.
     *
     * Only for the handful of types a browser opens in a sandbox — its PDF
     * viewer, its image decoder, and the <video> element. Everything else
     * downloads, because `inline` on a type the browser renders as markup is
     * how a stored file becomes a cross-site-scripting hole.
     */
    public function preview(File $file): BinaryFileResponse
    {
        Gate::authorize('view', $file);

        abort_unless($file->isPreviewable(), 404);

        return $this->stream($file, 'inline', 'file.previewed');
    }

    /**
     * Several files at once, as a zip.
     *
     * Every file is authorised on its own and written to the audit trail on its
     * own — a bundle is not a lighter kind of read, and "who has seen this
     * personnel file" has to stay answerable however the bytes left the
     * building. Anything the user may not have is left out silently rather than
     * failing the whole download: the selection they dragged a box around may
     * legitimately span folders they only partly own.
     */
    public function bundle(Request $request): BinaryFileResponse
    {
        $ids = collect($request->query('ids', []))
            ->filter(fn ($id) => is_string($id) || is_int($id))
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(self::MAX_BUNDLE);

        abort_if($ids->isEmpty(), 404);

        $files = File::query()
            ->visibleTo($request->user())
            ->whereKey($ids->all())
            ->with('folder')
            ->get()
            ->filter(fn (File $file) => Gate::allows('download', $file));

        abort_if($files->isEmpty(), 403);

        $disk = Storage::disk('documents');
        $archive = tempnam(sys_get_temp_dir(), 'drive-');

        abort_if($archive === false, 500);

        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::OVERWRITE) !== true) {
            @unlink($archive);
            abort(500);
        }

        $used = [];

        foreach ($files as $file) {
            if (! $disk->exists($file->storage_path)) {
                // One missing blob should not cost the user the rest of the
                // bundle. The gap is still worth investigating, so it is logged
                // as its own event rather than passed over in silence.
                $this->audit->log(
                    event: 'file.missing_from_disk',
                    subject: $file,
                    description: sprintf('“%s” was skipped in a bundle: its contents are missing.', $file->name),
                );

                continue;
            }

            $zip->addFile($disk->path($file->storage_path), $this->uniqueEntryName($file, $used));

            $this->audit->log(
                event: 'file.downloaded',
                subject: $file,
                properties: [
                    'folder' => $file->folder?->name,
                    'version_no' => $file->version_no,
                    'size' => $file->size,
                    'bundled' => true,
                ],
                description: sprintf('Downloaded “%s” as part of a %d-file bundle.', $file->name, $files->count()),
            );
        }

        $empty = $zip->numFiles === 0;
        $zip->close();

        if ($empty) {
            @unlink($archive);
            abort(404);
        }

        return response()
            ->download($archive, 'drive-files-'.now()->setTimezone(ph_tz())->format('Y-m-d-Hi').'.zip', [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }

    /** Two files may legitimately share a name; a zip entry may not. */
    private function uniqueEntryName(File $file, array &$used): string
    {
        $name = $this->filename($file);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        $n = 1;

        while (isset($used[mb_strtolower($candidate)])) {
            $n++;
            $candidate = $extension ? "{$base} ({$n}).{$extension}" : "{$base} ({$n})";
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function stream(File $file, string $disposition, string $event): BinaryFileResponse
    {
        $disk = Storage::disk('documents');

        if (! $disk->exists($file->storage_path)) {
            // A row with no bytes is not a missing page — it means something
            // happened to the store. Say so plainly rather than returning a 404
            // that reads like the file was simply deleted.
            throw DriveException::missingFromDisk($file);
        }

        $this->audit->log(
            event: $event,
            subject: $file,
            properties: [
                'folder' => $file->folder?->name,
                'version_no' => $file->version_no,
                'size' => $file->size,
            ],
            description: sprintf(
                '%s “%s”.',
                $disposition === 'inline' ? 'Viewed' : 'Downloaded',
                $file->name,
            ),
        );

        $filename = $this->filename($file);

        // BinaryFileResponse, not Storage::disk()->response()'s StreamedResponse
        // — the router calls ->prepare() on whatever a controller returns, and
        // only BinaryFileResponse answers a Range header with 206 + the
        // requested slice. A <video> element needs that to seek; without it,
        // every seek would have to re-fetch the file from byte zero.
        $response = new BinaryFileResponse($disk->path($file->storage_path));

        $response->headers->set('Content-Type', $file->mime);
        // Without this a browser may decide for itself what the bytes are,
        // which would defeat the point of restricting what can be inlined.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            $disposition,
            $filename,
            str_replace('%', '', Str::ascii($filename)),
        );

        return $response;
    }

    /** The display name, with the original extension put back on. */
    private function filename(File $file): string
    {
        $extension = $file->extension();
        $base = pathinfo($file->name, PATHINFO_FILENAME) ?: 'file';

        return $extension ? "{$base}.{$extension}" : $base;
    }
}
