<?php

namespace App\Enums;

/**
 * Who an app in the workspace catalog is published to.
 *
 * Deliberately the same widening shape as FolderVisibility, so a clerk learns
 * one vocabulary for "who can see this" and applies it to both files and
 * apps, instead of two separate permission languages.
 */
enum WorkspaceAppScope: string
{
    /** Only the office it belongs to. */
    case Department = 'department';

    /** Every onboarded office. */
    case Organization = 'organization';

    /** Reachable without an account — the same audience as the public portal. */
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'This office',
            self::Organization => 'Every office',
            self::Public => 'Public — no login needed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Department => 'blue',
            self::Organization => 'slate',
            self::Public => 'green',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
