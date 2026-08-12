<?php

namespace App\Enums;

/**
 * How settled an app in the workspace catalog is.
 *
 * Retired is kept rather than deleting the row — a link that used to work is
 * something a department administrator needs to explain, not something that
 * should silently disappear.
 */
enum WorkspaceAppStatus: string
{
    case Live = 'live';
    case Pilot = 'pilot';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live',
            self::Pilot => 'Pilot',
            self::Retired => 'Retired',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Live => 'green',
            self::Pilot => 'amber',
            self::Retired => 'slate',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
