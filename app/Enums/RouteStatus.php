<?php

namespace App\Enums;

/**
 * The state of one leg of a document's journey.
 *
 * A leg is a transmittal: office A hands the document to office B. It is opened
 * when the document is released and closed when B signs for it.
 *
 * There is no "deleted". A leg sent to the wrong office is Cancelled and stays
 * on the record — the point of a transmittal ledger is that it shows the
 * mistakes too.
 */
enum RouteStatus: string
{
    /** Released, not yet signed for. */
    case Pending = 'pending';

    /** Signed for. The receipt timestamp on this leg is now permanent. */
    case Received = 'received';

    /** Recalled by the sender before it was received. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting receipt',
            self::Received => 'Received',
            self::Cancelled => 'Recalled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Received => 'green',
            self::Cancelled => 'slate',
        };
    }
}
