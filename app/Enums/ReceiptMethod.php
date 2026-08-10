<?php

namespace App\Enums;

/**
 * How a document was signed for.
 *
 * This field is what lets one office run the system while the rest of the
 * municipal hall is still on paper. A leg received by an office that has not
 * onboarded is recorded as Manual: a clerk in the *sending* office enters the
 * name and time written on the signed transmittal. The trail stays complete and
 * honest, and it is always visible which receipts were witnessed by the system
 * and which were transcribed from paper.
 */
enum ReceiptMethod: string
{
    /** The receiving office signed in and pressed Receive. */
    case System = 'system';

    /** The routing slip was scanned and received on a phone. */
    case Qr = 'qr';

    /** Transcribed from a wet signature on a printed transmittal. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Received in system',
            self::Qr => 'Scanned routing slip',
            self::Manual => 'Signed on paper',
        };
    }

    /**
     * Whether the system itself witnessed the receipt. A manual receipt is
     * hearsay recorded by the sender — still evidence, but of a weaker kind,
     * and the interface should never present the two as identical.
     */
    public function isWitnessed(): bool
    {
        return $this !== self::Manual;
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
