<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something the backup center refuses.
 *
 * As with DriveException, every message is written for the person who will
 * read it — an IT administrator, not a developer — and appears on screen
 * unchanged.
 */
class BackupException extends RuntimeException
{
    public static function missingFromDisk(): self
    {
        return new self(
            'That backup is recorded but its file is no longer on disk. '
            .'It may have been removed outside the application — delete this entry and make a new backup.'
        );
    }

    public static function writeFailed(string $reason): self
    {
        return new self("The backup could not be written: {$reason}");
    }
}
