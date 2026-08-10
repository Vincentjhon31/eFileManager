<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Facades\URL;

/**
 * The URL a routing slip's QR code points at.
 *
 * Two decisions here, both about a link that is printed on paper and expected
 * to still work in three years.
 *
 * **No expiry.** A slip is folded into a folder and carried around the building
 * for a week, or filed and pulled out again months later. A link that stops
 * working would make the printed slip a liability rather than a shortcut.
 *
 * **Signed relatively, not absolutely.** An absolute signature covers the host,
 * so every printed slip would break the day the LGU moves the site, adds a
 * subdomain, or reaches it by IP from inside the hall. A relative signature
 * covers only the path, which is the part that actually needs protecting: it
 * stops someone editing a scanned link into another office's tracking number to
 * see what exists.
 *
 * The signature is not a credential. It proves the link came from us; the
 * scanner still has to sign in, and the policy still decides what they may see.
 *
 * Note for whoever operates this: rotating APP_KEY invalidates every signature,
 * and therefore every routing slip already printed and in circulation.
 */
class TrackingLink
{
    public static function for(Document $document): string
    {
        return url(static::relative($document));
    }

    /** The path and signature alone, as stored in the QR code's absolute form. */
    public static function relative(Document $document): string
    {
        return URL::signedRoute(
            'track',
            ['document' => $document->tracking_no],
            absolute: false,
        );
    }
}
