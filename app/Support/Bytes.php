<?php

namespace App\Support;

class Bytes
{
    /**
     * "2.4 MB".
     *
     * Written out rather than using Number::fileSize, which needs the intl
     * extension. Requiring an extension across the whole deployment — and
     * discovering it is missing on the host — is a poor trade for one label.
     */
    public static function human(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, $unit >= 2 ? 1 : 0).' '.$units[$unit];
    }
}
