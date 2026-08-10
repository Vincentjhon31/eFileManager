<?php

namespace App\Enums;

/**
 * Where a document stands.
 *
 * The status answers one question — "can anyone act on this right now, and who?"
 * It is deliberately small. Anything that varies without changing the answer to
 * that question (who it is assigned to, what action was requested, whether it is
 * overdue) is a field, not a status, so the state machine stays testable.
 *
 *   Draft ──release──> InTransit ──receive──> Received ──complete──> Completed
 *                          │                     │                       │
 *                       recall                release                 archive
 *                          ▼                     │                       ▼
 *                      (previous)                └──> InTransit      Archived
 *
 * Cancelled is reachable from Draft, InTransit and Received, and is terminal.
 */
enum DocumentStatus: string
{
    /** Registered but not yet released. Sits with the registering office. */
    case Draft = 'draft';

    /** Released and not yet signed for. The sender remains accountable. */
    case InTransit = 'in_transit';

    /** Signed for by the destination. On their desk, awaiting action. */
    case Received = 'received';

    /** Acted on. No further routing is expected. */
    case Completed = 'completed';

    /** Filed. Retained per RA 9470 but out of the working queues. */
    case Archived = 'archived';

    /** Registered in error and withdrawn. The trail is kept. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InTransit => 'In transit',
            self::Received => 'Received',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
            self::Cancelled => 'Cancelled',
        };
    }

    /** What a clerk should understand the status to mean. */
    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Registered but not yet sent to another office.',
            self::InTransit => 'Sent, and waiting for the receiving office to sign for it.',
            self::Received => 'Signed for and sitting with the holding office.',
            self::Completed => 'Acted on and closed.',
            self::Archived => 'Filed away for retention.',
            self::Cancelled => 'Withdrawn. Kept on record but no longer live.',
        };
    }

    /** Tailwind colour key, so the same status reads the same everywhere. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::InTransit => 'amber',
            self::Received => 'blue',
            self::Completed => 'green',
            self::Archived => 'slate',
            self::Cancelled => 'red',
        };
    }

    /** Whether the document is still moving through the LGU. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::InTransit, self::Received], true);
    }

    /** Whether the document may be released to another office from here. */
    public function allowsRelease(): bool
    {
        return in_array($this, [self::Draft, self::Received], true);
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return array<int, self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
