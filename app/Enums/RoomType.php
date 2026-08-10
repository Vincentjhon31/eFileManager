<?php

namespace App\Enums;

/**
 * What a room on the floor plan is for.
 *
 * The type decides whether a room means anything to the document system. An
 * office has a caseload and a door that lights up; a comfort room is drawn
 * because leaving it out would make the floor unrecognisable, and for no other
 * reason.
 */
enum RoomType: string
{
    /** A municipal office. Has a caseload, a door state, and a way in. */
    case Office = 'office';

    /** Session hall, conference hall. Belongs to an office but holds no queue. */
    case Meeting = 'meeting';

    /** Where citizens are received: waiting area, public assistance desk. */
    case Public = 'public';

    /** Comfort rooms, pantries, storage. Drawn for orientation only. */
    case Utility = 'utility';

    /** Stairs, lift, corridor, balcony, entrance. */
    case Circulation = 'circulation';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Meeting => 'Meeting room',
            self::Public => 'Public area',
            self::Utility => 'Utility',
            self::Circulation => 'Circulation',
        };
    }

    /**
     * Whether clicking this room leads anywhere.
     *
     * Only rooms that can hold a caseload. Making a comfort room clickable
     * would teach people that clicking sometimes does nothing, which is a
     * worse lesson than the room simply being scenery.
     */
    public function isNavigable(): bool
    {
        return in_array($this, [self::Office, self::Meeting], true);
    }

    /** Whether the door should carry a badge when work is waiting. */
    public function carriesBadge(): bool
    {
        return $this === self::Office;
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
