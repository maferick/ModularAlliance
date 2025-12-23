<?php
declare(strict_types=1);

namespace App\Core;

final class EsiCache
{
    private readonly RedisCache $redis;

    public function __construct(
        private readonly Db $db,
        private readonly EsiClient $esi,
        ?RedisCache $redis = null
    ) {
        // Optional L1 Redis cache (safe fallback). If config/env is missing or Redis is down, it becomes a no-op.
        $cfg = [];
        try {
            if (function_exists('app_config')) {
                $all = \app_config();
                if (is_array($all) && isset($all['redis']) && is_array($all['redis'])) {
                    $cfg = $all['redis'];
                }
            }
        } catch (\Throwable $e) {
            $cfg = [];
        }

        $this->redis = $redis ?? RedisCache::fromConfig($cfg);
    }

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
        }

        // Normalize cache key (stable hash)
        $cacheKey = hash('sha256', $urlKey);

        // L1 Redis key
        $rKey = 'esi:' . $scopeKey . ':' . $cacheKey;

        // Read from L1 Redis cache if allowed
        if (!$force && $this->redis->enabled()) {
            try {
                $hit = $this->redis->getJson($rKey);
                if (is_array($hit) && isset($hit['payload'], $hit['fetched'], $hit['ttl'])) {
                    $fetched = (int)$hit['fetched'];
                    $ttl = (int)$hit['ttl'];
                    if ($fetched > 0 && $ttl > 0 && time() < ($fetched + $ttl)) {
                        $decoded = json_decode((string)$hit['payload'], true);
                        if (is_array($decoded)) return $decoded;
                    }
                }
            } catch (\Throwable $ignore) {
                // Redis is optional; ignore and fall back to DB
            }
        }

        try {
            // Read from DB cache if allowed
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
                        $decoded = json_decode((string)$row['payload_json'], true);
                        if (is_array($decoded)) {
                            // Populate Redis L1 with remaining TTL
                            if ($this->redis->enabled()) {
                                $remain = max(1, ($fetched + $ttl) - time());
                                try {
                                    $this->redis->setJson($rKey, [
                                        'payload' => (string)$row['payload_json'],
                                        'fetched' => $fetched,
                                        'ttl'     => $ttl,
                                        'status'  => (int)($row['status_code'] ?? 200),
                                    ], $remain);
                                } catch (\Throwable $ignore) {}
                            }
                            return $decoded;
                        }
                    }
                }
            }

            // Cache miss: fetch from ESI or custom fetcher
            $data = null;

            if ($fetcher) {
                $data = $fetcher();
            } else {
                // Allow passing "GET /latest/..." as urlKey (legacy)
                $path = $urlKey;

                // If it looks like "GET /latest/..." strip method prefix
                if (preg_match('/^(GET|POST|PUT|DELETE)\s+(.+)$/i', $path, $m)) {
                    $path = $m[2];
                }

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

            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->db->run(
                "REPLACE INTO esi_cache (scope_key, cache_key, url, payload_json, fetched_at, ttl_seconds, status_code)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?)",
                [
                    $scopeKey,
                    $cacheKey,
                    $urlKey,
                    $payload,
                    $ttlSeconds,
                    200
                ]
            );

            // Write-through to Redis L1
            if ($this->redis->enabled()) {
                try {
                    $this->redis->setJson($rKey, [
                        'payload' => $payload,
                        'fetched' => time(),
                        'ttl'     => $ttlSeconds,
                        'status'  => 200,
                    ], max(1, $ttlSeconds));
                } catch (\Throwable $ignore) {}
            }

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

            // Also set a short-lived Redis entry (optional) to dampen retries
            if ($this->redis->enabled()) {
                try {
                    $payload = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $this->redis->setJson($rKey, [
                        'payload' => $payload,
                        'fetched' => time(),
                        'ttl'     => min($ttlSeconds, 300),
                        'status'  => 599,
                    ], min($ttlSeconds, 300));
                } catch (\Throwable $ignore) {}
            }

            throw $e;
        }
    }

    /**
     * Authenticated cache helper using Bearer tokens.
     *
     * @param array<int, int> $ignoreStatus
     */
    public function getCachedAuth(
        string $scopeKey,
        string $urlKey,
        int $ttlSeconds,
        string $accessToken,
        array $ignoreStatus = []
    ): array {
        $cacheKey = hash('sha256', $urlKey);
        $rKey = 'esi:' . $scopeKey . ':' . $cacheKey;

        if ($this->redis->enabled()) {
            try {
                $hit = $this->redis->getJson($rKey);
                if (is_array($hit) && isset($hit['payload'], $hit['fetched'], $hit['ttl'])) {
                    $fetched = (int)$hit['fetched'];
                    $ttl = (int)$hit['ttl'];
                    if ($fetched > 0 && $ttl > 0 && time() < ($fetched + $ttl)) {
                        $decoded = json_decode((string)$hit['payload'], true);
                        if (is_array($decoded)) return $decoded;
                    }
                }
            } catch (\Throwable $ignore) {}
        }

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
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) return $decoded;
            }
        }

        try {
            $path = $urlKey;
            if (preg_match('/^(GET|POST|PUT|DELETE)\s+(.+)$/i', $path, $m)) {
                $path = $m[2];
            }
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                $u = parse_url($path);
                $path = ($u['path'] ?? '/');
                if (!empty($u['query'])) $path .= '?' . $u['query'];
            }

            [$status, $data] = $this->esi->getWithStatus($path, $accessToken);
            if ($status >= 200 && $status < 300 && is_array($data)) {
                $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->db->run(
                    "REPLACE INTO esi_cache (scope_key, cache_key, url, payload_json, fetched_at, ttl_seconds, status_code)
                     VALUES (?, ?, ?, ?, NOW(), ?, ?)",
                    [$scopeKey, $cacheKey, $urlKey, $payload, $ttlSeconds, $status]
                );
                if ($this->redis->enabled()) {
                    try {
                        $this->redis->setJson($rKey, [
                            'payload' => $payload,
                            'fetched' => time(),
                            'ttl' => $ttlSeconds,
                            'status' => $status,
                        ], max(1, $ttlSeconds));
                    } catch (\Throwable $ignore) {}
                }
                return $data;
            }

            if (in_array($status, $ignoreStatus, true)) {
                $this->db->run(
                    "REPLACE INTO esi_cache (scope_key, cache_key, url, payload_json, fetched_at, ttl_seconds, status_code)
                     VALUES (?, ?, ?, ?, NOW(), ?, ?)",
                    [$scopeKey, $cacheKey, $urlKey, json_encode([]), min($ttlSeconds, 300), $status]
                );
                return [];
            }

            throw new \RuntimeException("ESI HTTP {$status}");
        } catch (\Throwable $e) {
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
            return [];
        }
    }
}
