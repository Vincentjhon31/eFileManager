<?php

namespace App\Exceptions;

use App\Models\File;
use App\Models\Folder;
use RuntimeException;

/**
 * Something the drive refuses.
 *
 * As with RoutingException, every message is written for the person who will
 * read it — a clerk uploading a scan — and appears on screen unchanged.
 */
class DriveException extends RuntimeException
{
    public static function noOffice(): self
    {
        return new self(
            'Your account is not assigned to an office, so it has no files of its own. '
            .'Ask your administrator to assign one.'
        );
    }

    public static function notYourOffice(Folder $folder): self
    {
        return new self(sprintf(
            '%s belongs to another office. You can read what they have shared, but not change it.',
            $folder->name,
        ));
    }

    public static function nameTaken(string $name): self
    {
        return new self("There is already something called “{$name}” here. Choose another name.");
    }

    public static function systemFolder(Folder $folder): self
    {
        return new self(sprintf(
            '“%s” is created and maintained by the system. Other records point at it, so it cannot be renamed or removed.',
            $folder->name,
        ));
    }

    public static function folderNotEmpty(Folder $folder): self
    {
        return new self(sprintf(
            '“%s” still has things in it. Empty it first — including anything in the trash — so that nothing is removed by accident.',
            $folder->name,
        ));
    }

    public static function extensionNotAllowed(string $extension): self
    {
        return new self(sprintf(
            '%s files cannot be uploaded here. Allowed: %s.',
            $extension === '' ? 'Files with no extension' : mb_strtoupper($extension),
            mb_strtoupper(implode(', ', config('drive.allowed_extensions', []))),
        ));
    }

    public static function tooLarge(int $megabytes): self
    {
        return new self("That file is larger than {$megabytes} MB. Split it or scan at a lower resolution.");
    }

    public static function identicalToCurrentVersion(File $file): self
    {
        return new self(sprintf(
            'That is byte for byte the same as the current version of “%s”, so nothing was changed. '
            .'Upload a different file, or leave it as it is.',
            $file->name,
        ));
    }

    public static function acrossOffices(): self
    {
        return new self('A file cannot be moved into another office\'s folders.');
    }

    public static function folderIntoItself(Folder $folder): self
    {
        return new self(sprintf(
            '“%s” cannot be moved into itself or into one of the folders inside it.',
            $folder->name,
        ));
    }

    public static function attachedToDocuments(File $file, int $count): self
    {
        return new self(sprintf(
            '“%s” is attached to %d tracked document(s) and is part of their record. '
            .'Detach it there first if it really must be destroyed.',
            $file->name,
            $count,
        ));
    }

    public static function missingFromDisk(File $file): self
    {
        return new self(sprintf(
            '“%s” is recorded but its contents are missing from the server. Report this to MIS — '
            .'do not re-upload over it, because the gap itself is worth investigating.',
            $file->name,
        ));
    }
}
