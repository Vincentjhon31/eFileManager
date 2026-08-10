<?php

namespace App\Enums;

/**
 * Who can open a folder and the files in it.
 *
 * The drive uses a different rule from document tracking, on purpose. A
 * document is visible to the offices it has passed through — that follows the
 * paper. A folder does not travel anywhere; it belongs to one office, and that
 * office decides who else may look inside.
 *
 * The levels widen in one direction only, and each is a deliberate act by
 * somebody in the owning office.
 */
enum FolderVisibility: string
{
    /** The person who made it. Drafts, personal working files. */
    case Private = 'private';

    /** Everyone in the owning office. The normal case. */
    case Department = 'department';

    /** Any signed-in employee. Shared forms, templates, circulars. */
    case Internal = 'internal';

    /**
     * Eligible for the public portal.
     *
     * Eligible, not published — as with document confidentiality, putting
     * something on the portal stays a separate, deliberate act with its own
     * confirmation and its own audit entry (Phase 6).
     */
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Only me',
            self::Department => 'My office',
            self::Internal => 'All LGU staff',
            self::Public => 'Public disclosure',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => 'Nobody else can open this folder, including your office head.',
            self::Department => 'Everyone in your office can open it.',
            self::Internal => 'Any signed-in employee, in any office, can open it.',
            self::Public => 'May be published on the public portal after a separate approval.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Private => 'red',
            self::Department => 'slate',
            self::Internal => 'blue',
            self::Public => 'green',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
