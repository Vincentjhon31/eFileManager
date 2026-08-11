<?php

namespace App\Enums;

/**
 * What kind of notice this is.
 *
 * Kept close to the words a municipal hall already uses on its bulletin board,
 * because the same person will be typing both.
 */
enum AnnouncementCategory: string
{
    case Notice = 'notice';
    case Event = 'event';
    case Advisory = 'advisory';
    case Bidding = 'bidding';
    case Vacancy = 'vacancy';

    public function label(): string
    {
        return match ($this) {
            self::Notice => 'Public notice',
            self::Event => 'Event',
            self::Advisory => 'Advisory',
            self::Bidding => 'Invitation to bid',
            self::Vacancy => 'Job vacancy',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Notice => 'A general notice to the public.',
            self::Event => 'A municipal event, activity or celebration.',
            self::Advisory => 'Weather, road closures, suspension of work or classes.',
            self::Bidding => 'An invitation to bid or a procurement notice.',
            self::Vacancy => 'A plantilla or job order vacancy.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Notice => 'slate',
            self::Event => 'blue',
            self::Advisory => 'amber',
            self::Bidding => 'green',
            self::Vacancy => 'blue',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
