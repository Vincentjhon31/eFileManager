<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read a backup's bytes.
 *
 * Same shape as DocumentFileController: the 'backups' disk sits outside the
 * web root, every read is authorised first, and every read is written to the
 * audit trail. Route middleware already checks this same permission before
 * the request arrives; this second check is what stands between a stranger
 * and the whole database if that middleware is ever misconfigured.
 */
class BackupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function download(Backup $backup): StreamedResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $disk = Storage::disk('backups');

        if (! $disk->exists($backup->disk_path)) {
            throw BackupException::missingFromDisk();
        }

        $this->audit->log(
            event: 'backup.downloaded',
            subject: $backup,
            properties: ['type' => $backup->type->value, 'size' => $backup->size],
            description: 'Downloaded a '.$backup->type->label().' backup ('.$backup->humanSize().').',
        );

        return $disk->download($backup->disk_path, basename($backup->disk_path));
    }
}
