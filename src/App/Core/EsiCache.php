<?php
declare(strict_types=1);

namespace App\Core;

final class EsiCache
{
    public function __construct(private readonly Db $db, private readonly EsiClient $esi) {}

    public function getCached(string $scopeKey, string $urlKey, int $ttlSeconds, callable $fetcher, bool $force = false): array
    {
        $cacheKey = hash('sha256', $urlKey);

        if (!$force) {
            $row = $this->db->one(
                "SELECT payload_json, fetched_at, ttl_seconds
                 FROM esi_cache
                 WHERE scope_key=? AND cache_key=?
                 LIMIT 1",
                [$scopeKey, $cacheKey]
            );

            if ($row) {
                $fetched = strtotime((string)$row['fetched_at']) ?: 0;
                $ttl = (int)$row['ttl_seconds'];
                if (time() < ($fetched + $ttl)) {
                    $data = json_decode((string)$row['payload_json'], true);
                    if (is_array($data)) return $data;
                }
            }
        }

        $data = $fetcher(); // must return array
        $this->db->run(
            "REPLACE INTO esi_cache (scope_key, cache_key, url, payload_json, fetched_at, ttl_seconds, status_code)
             VALUES (?, ?, ?, ?, NOW(), ?, 200)",
            [
                $scopeKey,
                $cacheKey,
                $urlKey,
                json_encode($data, JSON_UNESCAPED_SLASHES),
                $ttlSeconds
            ]
        );

        return $data;
    }
}

