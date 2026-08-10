<?php

namespace App\Services;

use App\Enums\FolderVisibility;
use App\Exceptions\DriveException;
use App\Models\Department;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything that writes to the drive.
 *
 * Nothing outside this class should create a File row or touch the documents
 * disk. A row whose bytes are missing, or bytes with no row that nothing will
 * ever clean up, is the kind of quiet corruption a records office discovers
 * years later when it matters — so the row and the file are always created,
 * moved and removed together, here.
 *
 * Three properties worth stating plainly, because they are what make this safe
 * to put government records into:
 *
 *  1. **Nothing is overwritten.** Uploading a replacement adds a version and
 *     moves a flag. Every version an office has ever held is still on disk.
 *  2. **Nothing is destroyed by accident.** Deleting means trash, restorable
 *     indefinitely. Actually destroying bytes is a separate, privileged act
 *     that refuses to touch anything attached to a tracked document.
 *  3. **Stored files have no extension and live outside the web root.** Even a
 *     misconfigured document root cannot be talked into executing one.
 */
class FileStorageService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    */

    /**
     * The office's top-level folder, created on first use.
     *
     * firstOrCreate rather than a locked upsert: the only way to get two is for
     * an office's very first two visits to the drive to land in the same
     * millisecond, and the result would be a duplicate folder name — visible,
     * harmless and fixable — rather than a lost or misfiled record. The seeder
     * creates these up front so it should never arise at all.
     */
    public function rootFolderFor(Department $office, ?User $by = null): Folder
    {
        return $this->systemFolder($office, Folder::ROOT_NAME, null, $by);
    }

    /** Where scans attached to tracked documents are filed. */
    public function documentScansFolderFor(Department $office, ?User $by = null): Folder
    {
        return $this->systemFolder(
            $office,
            Folder::DOCUMENTS_NAME,
            $this->rootFolderFor($office, $by),
            $by,
        );
    }

    private function systemFolder(Department $office, string $name, ?Folder $parent, ?User $by): Folder
    {
        return Folder::firstOrCreate(
            [
                'department_id' => $office->getKey(),
                'parent_id' => $parent?->getKey(),
                'name' => $name,
            ],
            [
                'visibility' => FolderVisibility::Department,
                'is_system' => true,
                'created_by' => $by?->getKey(),
            ],
        );
    }

    public function createFolder(
        Department $office,
        ?Folder $parent,
        string $name,
        FolderVisibility $visibility,
        User $by,
    ): Folder {
        $name = $this->cleanName($name);

        if ($parent && $parent->department_id !== $office->getKey()) {
            throw DriveException::notYourOffice($parent);
        }

        $this->assertNameIsFree($office, $parent, $name);

        $folder = Folder::create([
            'department_id' => $office->getKey(),
            'parent_id' => $parent?->getKey(),
            'name' => $name,
            'visibility' => $visibility,
            'is_system' => false,
            'created_by' => $by->getKey(),
        ]);

        $this->audit->log(
            event: 'folder.created',
            subject: $folder,
            properties: ['visibility' => $visibility->value, 'parent' => $parent?->name],
            description: "Created folder “{$folder->name}”.",
            actor: $by,
        );

        return $folder;
    }

    public function renameFolder(Folder $folder, string $name, User $by): Folder
    {
        if ($folder->is_system) {
            throw DriveException::systemFolder($folder);
        }

        $name = $this->cleanName($name);
        $before = $folder->name;

        if ($name !== $before) {
            $this->assertNameIsFree($folder->department, $folder->parent, $name, $folder->getKey());
            $folder->update(['name' => $name]);

            $this->audit->log(
                event: 'folder.renamed',
                subject: $folder,
                properties: ['before' => $before, 'after' => $name],
                description: "Renamed folder “{$before}” to “{$name}”.",
                actor: $by,
            );
        }

        return $folder;
    }

    public function setFolderVisibility(Folder $folder, FolderVisibility $visibility, User $by): Folder
    {
        $before = $folder->visibility;

        if ($before === $visibility) {
            return $folder;
        }

        $folder->update(['visibility' => $visibility]);

        // Widening who can read a folder is a disclosure decision, so it gets
        // its own audit entry rather than being folded into a generic update.
        $this->audit->log(
            event: 'folder.visibility_changed',
            subject: $folder,
            properties: ['before' => $before->value, 'after' => $visibility->value],
            description: sprintf(
                'Folder “%s” changed from %s to %s.',
                $folder->name,
                mb_strtolower($before->label()),
                mb_strtolower($visibility->label()),
            ),
            actor: $by,
        );

        return $folder;
    }

    /** Only an empty folder can go, and "empty" includes the trash. */
    public function deleteFolder(Folder $folder, User $by): void
    {
        if ($folder->is_system) {
            throw DriveException::systemFolder($folder);
        }

        $hasContents = $folder->children()->exists()
            || $folder->files()->withTrashed()->exists();

        if ($hasContents) {
            throw DriveException::folderNotEmpty($folder);
        }

        $name = $folder->name;
        $folder->delete();

        $this->audit->log(
            event: 'folder.deleted',
            properties: ['name' => $name, 'department_id' => $folder->department_id],
            description: "Deleted empty folder “{$name}”.",
            actor: $by,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    public function store(UploadedFile $upload, Folder $folder, User $by, ?string $name = null): File
    {
        $this->assertUploadIsAllowed($upload);

        return $this->writeVersion(
            upload: $upload,
            folder: $folder,
            by: $by,
            name: $this->cleanName($name ?: $upload->getClientOriginalName()),
            versionGroupId: (string) Str::uuid(),
            versionNo: 1,
            supersedes: null,
        );
    }

    /**
     * Add a version. The previous one is kept and simply stops being current.
     */
    public function storeNewVersion(File $current, UploadedFile $upload, User $by): File
    {
        $this->assertUploadIsAllowed($upload);

        // Re-uploading the same scan is a common slip — a clerk who is not sure
        // whether the first attempt worked. Refusing it keeps the version
        // history meaningful instead of filling it with identical entries.
        if (hash_file('sha256', $upload->getRealPath()) === $current->sha256) {
            throw DriveException::identicalToCurrentVersion($current);
        }

        return $this->writeVersion(
            upload: $upload,
            folder: $current->folder,
            by: $by,
            name: $current->name,
            versionGroupId: $current->version_group_id,
            versionNo: ((int) File::withTrashed()
                ->where('version_group_id', $current->version_group_id)
                ->max('version_no')) + 1,
            supersedes: $current,
        );
    }

    public function rename(File $file, string $name, User $by): File
    {
        $before = $file->name;
        $name = $this->cleanName($name);

        if ($name === $before) {
            return $file;
        }

        // The whole version group carries one name, so history stays legible.
        File::withTrashed()->where('version_group_id', $file->version_group_id)
            ->update(['name' => $name]);

        $file->refresh();

        $this->audit->log(
            event: 'file.renamed',
            subject: $file,
            properties: ['before' => $before, 'after' => $name],
            description: "Renamed “{$before}” to “{$name}”.",
            actor: $by,
        );

        return $file;
    }

    public function move(File $file, Folder $target, User $by): File
    {
        if ($target->department_id !== $file->department_id) {
            throw DriveException::acrossOffices();
        }

        $from = $file->folder?->name;

        File::withTrashed()->where('version_group_id', $file->version_group_id)
            ->update(['folder_id' => $target->getKey()]);

        $file->refresh();

        $this->audit->log(
            event: 'file.moved',
            subject: $file,
            properties: ['from' => $from, 'to' => $target->name],
            description: "Moved “{$file->name}” to “{$target->name}”.",
            actor: $by,
        );

        return $file;
    }

    /** To the trash, with every version, restorable indefinitely. */
    public function trash(File $file, User $by): void
    {
        DB::transaction(function () use ($file) {
            File::where('version_group_id', $file->version_group_id)->get()
                ->each->delete();
        });

        $this->audit->log(
            event: 'file.trashed',
            subject: $file,
            properties: ['folder' => $file->folder?->name, 'versions' => $file->version_no],
            description: "Moved “{$file->name}” to the trash.",
            actor: $by,
        );
    }

    public function restore(File $file, User $by): void
    {
        DB::transaction(function () use ($file) {
            File::withTrashed()->where('version_group_id', $file->version_group_id)->get()
                ->each->restore();
        });

        $this->audit->log(
            event: 'file.restored',
            subject: $file,
            description: "Restored “{$file->name}” from the trash.",
            actor: $by,
        );
    }

    /**
     * Destroy the bytes. There is no undo, which is why it refuses to touch
     * anything that forms part of a tracked document's record.
     */
    public function purge(File $file, User $by): void
    {
        $versions = File::withTrashed()
            ->where('version_group_id', $file->version_group_id)
            ->get();

        $attached = DB::table('document_files')
            ->whereIn('file_id', $versions->modelKeys())
            ->count();

        if ($attached > 0) {
            throw DriveException::attachedToDocuments($file, $attached);
        }

        $name = $file->name;
        $paths = $versions->pluck('storage_path')->all();

        DB::transaction(function () use ($versions) {
            $versions->each->forceDelete();
        });

        // Rows first, bytes second. If this half fails the result is an orphan
        // blob wasting disk, which is recoverable; the other order would leave
        // a row pointing at nothing, which reads as evidence of tampering.
        Storage::disk('documents')->delete($paths);

        $this->audit->log(
            event: 'file.purged',
            properties: ['name' => $name, 'versions' => count($paths), 'department_id' => $file->department_id],
            description: "Permanently destroyed “{$name}” and all {$file->version_no} of its versions.",
            actor: $by,
        );
    }

    /**
     * Whether the bytes on disk still hash to what was recorded on upload.
     *
     * Not run on every download — hashing a 40 MB scan on each read would be
     * felt. It is here so that an integrity sweep can be run deliberately, and
     * so the question can be answered when somebody asks it.
     */
    public function verify(File $file): bool
    {
        $disk = Storage::disk('documents');

        if (! $disk->exists($file->storage_path)) {
            return false;
        }

        return hash('sha256', $disk->get($file->storage_path)) === $file->sha256;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function writeVersion(
        UploadedFile $upload,
        Folder $folder,
        User $by,
        string $name,
        string $versionGroupId,
        int $versionNo,
        ?File $supersedes,
    ): File {
        // Read from the temporary file before it is moved.
        $sha256 = hash_file('sha256', $upload->getRealPath());
        $mime = $upload->getMimeType() ?: 'application/octet-stream';
        $size = $upload->getSize() ?: 0;
        $originalName = $upload->getClientOriginalName();

        $path = $this->pathFor($folder);

        Storage::disk('documents')->putFileAs(dirname($path), $upload, basename($path));

        try {
            return DB::transaction(function () use (
                $folder, $by, $name, $originalName, $mime, $size, $sha256,
                $path, $versionGroupId, $versionNo, $supersedes
            ) {
                $supersedes?->update(['is_current' => false]);

                $file = File::create([
                    'folder_id' => $folder->getKey(),
                    'department_id' => $folder->department_id,
                    'name' => $name,
                    'original_name' => $originalName,
                    'mime' => $mime,
                    'size' => $size,
                    'sha256' => $sha256,
                    'storage_path' => $path,
                    'version_group_id' => $versionGroupId,
                    'version_no' => $versionNo,
                    'is_current' => true,
                    'uploaded_by' => $by->getKey(),
                ]);

                $this->audit->log(
                    event: $supersedes ? 'file.version_added' : 'file.uploaded',
                    subject: $file,
                    properties: [
                        'folder' => $folder->name,
                        'size' => $size,
                        'mime' => $mime,
                        'sha256' => $sha256,
                        'version_no' => $versionNo,
                    ],
                    description: $supersedes
                        ? "Uploaded version {$versionNo} of “{$name}”."
                        : "Uploaded “{$name}” to “{$folder->name}”.",
                    actor: $by,
                );

                return $file;
            });
        } catch (Throwable $e) {
            // The bytes landed but the row did not. Clean up rather than leave
            // a file nothing will ever account for.
            Storage::disk('documents')->delete($path);

            throw $e;
        }
    }

    /**
     * Where the bytes go: partitioned by office and month, named with a UUID
     * and no extension.
     *
     * The UUID means a filename from a user can never influence a path, so
     * there is nothing to traverse and nothing to collide. The missing
     * extension means that if this directory is ever exposed by a
     * misconfiguration, there is no file in it a web server would agree to run.
     */
    private function pathFor(Folder $folder): string
    {
        return sprintf(
            '%d/%s/%s',
            $folder->department_id,
            now()->setTimezone(ph_tz())->format('Y/m'),
            Str::uuid(),
        );
    }

    private function assertUploadIsAllowed(UploadedFile $upload): void
    {
        $extension = mb_strtolower($upload->getClientOriginalExtension());

        if (! in_array($extension, config('drive.allowed_extensions', []), true)) {
            throw DriveException::extensionNotAllowed($extension);
        }

        $limit = (int) config('drive.max_upload_mb', 50);

        if ($upload->getSize() > $limit * 1024 * 1024) {
            throw DriveException::tooLarge($limit);
        }
    }

    private function assertNameIsFree(Department $office, ?Folder $parent, string $name, ?int $ignoreId = null): void
    {
        $taken = Folder::query()
            ->where('department_id', $office->getKey())
            ->where('parent_id', $parent?->getKey())
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw DriveException::nameTaken($name);
        }
    }

    /** Strip anything that would make a name confusing or a path dangerous. */
    private function cleanName(string $name): string
    {
        // Directory separators and control characters have no business in a
        // display name, and their absence means a name can never be mistaken
        // for a path by anything downstream.
        $name = preg_replace('/[\/\\\\\x00-\x1F]/u', '', trim($name)) ?? '';

        return mb_substr($name, 0, 200) ?: 'Untitled';
    }
}
