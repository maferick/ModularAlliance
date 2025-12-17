<?php
declare(strict_types=1);

namespace App\Core;

final class EsiCache
{
    public function __construct(
        private readonly Db $db,
        private readonly EsiClient $esi
    ) {}

    /**
     * Backwards-compatible signature:
     *   - getCached($scopeKey, $urlKey, $ttlSeconds)                          // uses internal EsiClient
     *   - getCached($scopeKey, $urlKey, $ttlSeconds, true)                    // force refresh
     *   - getCached($scopeKey, $urlKey, $ttlSeconds, fn() => [...])          // custom fetcher
     *   - getCached($scopeKey, $urlKey, $ttlSeconds, fn() => [...], true)    // custom fetcher + force
     */
    public function getCached(
        string $scopeKey,
        string $urlKey,
        int $ttlSeconds,
        mixed $fetcherOrForce = null,
        bool $force = false
    ): array {
        // Determine call style
        $fetcher = null;

        if (is_callable($fetcherOrForce)) {
            // Old style: 4th is fetcher
            $fetcher = $fetcherOrForce;
        } elseif (is_bool($fetcherOrForce)) {
            // New style: 4th is force
            $force = $fetcherOrForce;
        } elseif ($fetcherOrForce !== null) {
            throw new \InvalidArgumentException('EsiCache::getCached() 4th argument must be callable, bool, or null');
        }

        $cacheKey = hash('sha256', $urlKey);

        // Read from cache if allowed
        if (!$force) {
            $row = $this->db->one(
                "SELECT payload_json, fetched_at, ttl_seconds, status_code
                 FROM esi_cache
                 WHERE scope_key=? AND cache_key=?
                 LIMIT 1",
                [$scopeKey, $cacheKey]
            );

            if ($row) {
                $fetched = strtotime((string)$row['fetched_at']) ?: 0;
                $ttl     = (int)$row['ttl_seconds'];

                if (time() < ($fetched + $ttl)) {
                    $data = json_decode((string)$row['payload_json'], true);
                    if (is_array($data)) return $data;
                }
            }
        }

        // Fetch fresh
        try {
            if ($fetcher) {
                $data = $fetcher();
            } else {
                // Default behavior: treat $urlKey as ESI path or full URL
                // If it looks like "GET /latest/..." strip method prefix
                $path = $urlKey;
                if (str_starts_with($path, 'GET ')) {
                    $path = trim(substr($path, 4));
                }

                // If it's a full URL, pass as-is via EsiClient->get() by converting to path
                // EsiClient->get() expects a path like "/latest/..."
                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    $u = parse_url($path);
                    $path = ($u['path'] ?? '/');
                    if (!empty($u['query'])) $path .= '?' . $u['query'];
                }

                $data = $this->esi->get($path);
            }

            if (!is_array($data)) {
                throw new \RuntimeException('ESI fetcher did not return an array');
            }

            $this->db->run(
                "REPLACE INTO esi_cache (scope_key, cache_key, url, payload_json, fetched_at, ttl_seconds, status_code)
                 VALUES (?, ?, ?, ?, NOW(), ?, 200)",
                [
                    $scopeKey,
                    $cacheKey,
                    $urlKey,
                    json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $ttlSeconds
                ]
            );

            return $data;
        } catch (\Throwable $e) {
            // Cache failure entry (best effort) to avoid hammering ESI in loops
            try {
                $this->db->run(
                    "REPLACE INTO esi_cache (scope_key, cache_key, url, payload_json, fetched_at, ttl_seconds, status_code)
                     VALUES (?, ?, ?, ?, NOW(), ?, ?)",
                    [
                        $scopeKey,
                        $cacheKey,
                        $urlKey,
                        json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        min($ttlSeconds, 300),
                        599
                    ]
                );
            } catch (\Throwable $ignore) {}

            throw $e;
        }
    }
}
