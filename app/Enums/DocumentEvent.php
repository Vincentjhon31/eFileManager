<?php

namespace App\Enums;

/**
 * The acts that make up a document's timeline.
 *
 * This is the vocabulary of the custody record — what a clerk would have written
 * in the logbook. It is an enum rather than free strings so that rendering the
 * timeline is exhaustive: adding an act without deciding how it reads to a user
 * is a compile-time problem, not a blank row in production.
 */
enum DocumentEvent: string
{
    case Registered = 'registered';
    case Released = 'released';
    case Recalled = 'recalled';
    case Received = 'received';
    case Returned = 'returned';
    case Assigned = 'assigned';
    case Remarked = 'remarked';
    case Completed = 'completed';
    case Reopened = 'reopened';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Released => 'Released',
            self::Recalled => 'Recalled',
            self::Received => 'Received',
            self::Returned => 'Returned',
            self::Assigned => 'Assigned',
            self::Remarked => 'Remarks added',
            self::Completed => 'Completed',
            self::Reopened => 'Reopened',
            self::Archived => 'Archived',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Registered => 'slate',
            self::Released => 'amber',
            self::Recalled => 'slate',
            self::Received => 'blue',
            self::Returned => 'amber',
            self::Assigned => 'slate',
            self::Remarked => 'slate',
            self::Completed => 'green',
            self::Reopened => 'amber',
            self::Archived => 'slate',
            self::Cancelled => 'red',
        };
    }

    /** The matching name in the system-wide audit trail. */
    public function auditEvent(): string
    {
        return 'document.'.$this->value;
    }
}
