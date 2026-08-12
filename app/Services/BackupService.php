<?php

namespace App\Services;

use App\Enums\BackupType;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\Department;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Makes, reads and prunes backups.
 *
 * No queue: a Hostinger-style shared host has no persistent process to run
 * one, so every backup is made synchronously, in the request that asked for
 * it — the same constraint the morning digest and the tracking-number
 * generator already live with elsewhere in this app.
 *
 * The database dump is written in plain PHP rather than shelling out to
 * mysqldump: shared hosting frequently disables exec() and proc_open(), and a
 * feature that only works on hosts where it happens not to be disabled is a
 * worse trade than a dump slower than the binary would be.
 */
class BackupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createDatabaseBackup(User $by): Backup
    {
        $sql = $this->dumpDatabase();
        $filename = 'database/db-'.now()->format('Y-m-d-His').'.sql.gz';

        $this->write($filename, gzencode($sql, 9));

        $backup = $this->record(BackupType::Database, $filename, $by);

        $this->audit->log(
            event: 'backup.created',
            subject: $backup,
            properties: ['type' => 'database', 'size' => $backup->size],
            description: 'Created a database backup ('.$backup->humanSize().').',
            actor: $by,
        );

        $this->prune(BackupType::Database);

        return $backup;
    }

    public function createFilesBackup(User $by): Backup
    {
        $filename = 'files/files-'.now()->format('Y-m-d-His').'.zip';

        $this->zipDocumentsDisk($filename);

        $backup = $this->record(BackupType::Files, $filename, $by);

        $this->audit->log(
            event: 'backup.created',
            subject: $backup,
            properties: ['type' => 'files', 'size' => $backup->size],
            description: 'Created a files backup ('.$backup->humanSize().').',
            actor: $by,
        );

        $this->prune(BackupType::Files);

        return $backup;
    }

    public function delete(Backup $backup, User $by): void
    {
        Storage::disk('backups')->delete($backup->disk_path);

        $this->audit->log(
            event: 'backup.deleted',
            subject: $backup,
            properties: ['type' => $backup->type->value, 'size' => $backup->size],
            description: 'Deleted a '.$backup->type->label().' backup ('.$backup->humanSize().').',
            actor: $by,
        );

        $backup->delete();
    }

    /**
     * Bytes stored per office, current files and trashed-but-not-purged ones
     * alike — trashed files still occupy real disk space until someone with
     * Permission::SettingsManage destroys them for good.
     *
     * @return Collection<int, array{department: Department, file_count: int, total_size: int}>
     */
    public function storageUsageByDepartment()
    {
        $totals = File::withTrashed()
            ->selectRaw('department_id, COUNT(*) as file_count, COALESCE(SUM(size), 0) as total_size')
            ->groupBy('department_id')
            ->get()
            ->keyBy('department_id');

        return Department::query()
            ->internal()
            ->routable()
            ->get()
            ->map(fn (Department $department) => [
                'department' => $department,
                'file_count' => (int) ($totals[$department->id]->file_count ?? 0),
                'total_size' => (int) ($totals[$department->id]->total_size ?? 0),
            ])
            ->sortByDesc('total_size')
            ->values();
    }

    public function databaseSizeBytes(): int
    {
        $database = DB::connection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes '
            .'FROM information_schema.tables WHERE table_schema = ?',
            [$database],
        );

        return (int) ($row->bytes ?? 0);
    }

    /** Free space on the volume backups are written to. */
    public function freeDiskSpaceBytes(): int
    {
        $bytes = @disk_free_space(storage_path('app'));

        return $bytes === false ? 0 : (int) $bytes;
    }

    private function record(BackupType $type, string $filename, User $by): Backup
    {
        return Backup::create([
            'type' => $type,
            'disk_path' => $filename,
            'size' => Storage::disk('backups')->size($filename),
            'created_by' => $by->getKey(),
        ]);
    }

    /** Keep only the newest config('backups.keep_per_type') of each type. */
    private function prune(BackupType $type): void
    {
        $keep = max(1, (int) config('backups.keep_per_type', 5));

        Backup::query()
            ->where('type', $type)
            ->orderByDesc('created_at')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (Backup $stale) {
                Storage::disk('backups')->delete($stale->disk_path);
                $stale->delete();
            });
    }

    /**
     * The whole database as one SQL statement string: schema and data, table
     * by table, in the order MySQL reports them.
     *
     * Built in memory rather than streamed to disk. Fine for a municipal
     * records database; this is not built to back up a data warehouse.
     */
    private function dumpDatabase(): string
    {
        $database = DB::connection()->getDatabaseName();
        $pdo = DB::connection()->getPdo();

        $lines = [
            '-- '.$database.' — '.now()->toDateTimeString(),
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        $tableKey = "Tables_in_{$database}";

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = $row->$tableKey;

            $create = DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $lines[] = "DROP TABLE IF EXISTS `{$table}`;";
            $lines[] = $create->{'Create Table'}.';';
            $lines[] = '';

            $rows = DB::table($table)->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $columns = array_keys((array) $rows->first());
            $columnList = implode('`, `', $columns);

            foreach (array_chunk($rows->all(), 500) as $chunk) {
                $tuples = array_map(
                    fn ($row) => '('.implode(', ', array_map(
                        fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                        (array) $row,
                    )).')',
                    $chunk,
                );

                $lines[] = "INSERT INTO `{$table}` (`{$columnList}`) VALUES\n".implode(",\n", $tuples).';';
                $lines[] = '';
            }
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode("\n", $lines);
    }

    /** Everything on the 'documents' disk, as one archive. */
    private function zipDocumentsDisk(string $filename): void
    {
        $disk = Storage::disk('backups');
        $disk->makeDirectory(dirname($filename));

        $zip = new ZipArchive;
        $absolutePath = $disk->path($filename);

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw BackupException::writeFailed('the archive could not be created.');
        }

        $root = Storage::disk('documents')->path('');
        $fileCount = 0;

        if (is_dir($root)) {
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $files */
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $zip->addFile($file->getPathname(), str_replace('\\', '/', $relative));
                $fileCount++;
            }
        }

        // libzip silently declines to write the archive to disk if it ends up
        // with zero entries — an empty drive would otherwise produce a backup
        // that records a size but leaves no file behind to download.
        $zip->addFromString('MANIFEST.txt', sprintf(
            "Bongabong eFileManager files backup\nMade: %s\nFiles archived: %d\n",
            now()->toDateTimeString(),
            $fileCount,
        ));

        $zip->close();
    }

    private function write(string $filename, string|false $contents): void
    {
        if ($contents === false) {
            throw BackupException::writeFailed('the data could not be compressed.');
        }

        $disk = Storage::disk('backups');
        $disk->makeDirectory(dirname($filename));

        if (! $disk->put($filename, $contents)) {
            throw BackupException::writeFailed('the disk write failed.');
        }
    }
}
