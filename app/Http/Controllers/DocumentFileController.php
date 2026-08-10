<?php

namespace App\Http\Controllers;

use App\Exceptions\DriveException;
use App\Models\File;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(private readonly AuditLogger $audit) {}

    public function download(File $file): StreamedResponse
    {
        Gate::authorize('download', $file);

        return $this->stream($file, 'attachment', 'file.downloaded');
    }

    /**
     * Show it in the browser rather than saving it.
     *
     * Only for the handful of types a browser opens in a sandbox — its PDF
     * viewer and its image decoder. Everything else downloads, because
     * `inline` on a type the browser renders as markup is how a stored file
     * becomes a cross-site-scripting hole.
     */
    public function preview(File $file): StreamedResponse
    {
        Gate::authorize('view', $file);

        abort_unless($file->isPreviewable(), 404);

        return $this->stream($file, 'inline', 'file.previewed');
    }

    private function stream(File $file, string $disposition, string $event): StreamedResponse
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

        return $disk->response($file->storage_path, $this->filename($file), [
            'Content-Type' => $file->mime,
            // Without this a browser may decide for itself what the bytes are,
            // which would defeat the point of restricting what can be inlined.
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    /** The display name, with the original extension put back on. */
    private function filename(File $file): string
    {
        $extension = $file->extension();
        $base = pathinfo($file->name, PATHINFO_FILENAME) ?: 'file';

        return $extension ? "{$base}.{$extension}" : $base;
    }
}
