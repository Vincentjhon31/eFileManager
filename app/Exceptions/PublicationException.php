<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something the public portal refuses.
 *
 * As elsewhere, every message is written for the person who will read it — the
 * focal person putting a disclosure up — and reaches them unchanged.
 */
class PublicationException extends RuntimeException
{
    public static function alreadyPublished(string $title): self
    {
        return new self("“{$title}” is already on the public page.");
    }

    public static function neverPublished(): self
    {
        return new self('This was never published, so there is nothing to take down.');
    }

    public static function nothingToPublish(): self
    {
        return new self('There is no text to publish. Write the notice first.');
    }

    public static function expiresBeforeItAppears(): self
    {
        return new self(
            'This notice would expire before, or at the moment, it appears. '
            .'Check the publish date against the expiry date.'
        );
    }

    public static function fileIsInTheTrash(string $name): self
    {
        return new self(
            "“{$name}” is in the trash. Restore it before disclosing it — a public link "
            .'to a deleted file would break silently and look like a withdrawal nobody recorded.'
        );
    }

    public static function alreadyNominated(string $name): self
    {
        return new self(
            "“{$name}” has already been prepared for disclosure. Edit that entry rather than "
            .'making a second one, so the public are not given two links that disagree about '
            .'when it was disclosed.'
        );
    }
}
