<?php
declare(strict_types=1);

namespace App\Core;

final class EsiDateTime
{
    /**
     * Parse an ESI ISO-8601 datetime string into MySQL DATETIME (UTC).
     */
    public static function parseEsiDatetimeToMysql(?string $isoString): ?string
    {
        if ($isoString === null) {
            return null;
        }
        $isoString = trim($isoString);
        if ($isoString === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($isoString);
            $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
            return $utc->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
