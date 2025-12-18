<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Optional Redis cache wrapper (safe fallback).
 *
 * - If PHP Redis extension is missing or connection fails: enabled() === false and operations become no-ops.
 * - Uses a configurable prefix to keep keys namespaced.
 */
final class RedisCache
{
    private bool $enabled = false;
    private ?\Redis $r = null;
    private string $prefix = 'portal:';

    private function __construct() {}

    public static function fromConfig(array $redisCfg = []): self
    {
        $self = new self();

        if (!extension_loaded('redis')) {
            return $self;
        }

        $host = (string)($redisCfg['host'] ?? getenv('REDIS_HOST') ?: '');
        $port = (int)($redisCfg['port'] ?? getenv('REDIS_PORT') ?: 6379);
        $pass = (string)($redisCfg['password'] ?? getenv('REDIS_PASSWORD') ?: getenv('REDIS_PASS') ?: '');
        $db   = (int)($redisCfg['db'] ?? getenv('REDIS_DB') ?: 0);
        $timeout = (float)($redisCfg['timeout'] ?? getenv('REDIS_TIMEOUT') ?: 0.20);
        $prefix  = (string)($redisCfg['prefix'] ?? getenv('REDIS_PREFIX') ?: 'portal:');

        if ($host === '') {
            // No config; keep disabled
            $self->prefix = $prefix;
            return $self;
        }

        try {
            $r = new \Redis();
            if (!$r->connect($host, $port, $timeout)) {
                return $self;
            }
            if ($pass !== '') {
                if (!$r->auth($pass)) return $self;
            }
            if ($db !== 0) {
                if (!$r->select($db)) return $self;
            }

            // Basic health check
            $ping = $r->ping();
            if ($ping === false) return $self;

            $self->r = $r;
            $self->enabled = true;
            $self->prefix = $prefix;
            return $self;
        } catch (\Throwable $e) {
            return $self;
        }
    }

    /**
     * Compatibility shim: some older code may call RedisCache::connect().
     */
    public static function connect(array $redisCfg = []): self
    {
        return self::fromConfig($redisCfg);
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->r instanceof \Redis;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): ?string
    {
        if (!$this->enabled()) return null;
        try {
            $v = $this->r->get($this->k($key));
            return $v === false ? null : (string)$v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttlSeconds): bool
    {
        if (!$this->enabled()) return false;
        try {
            $ttlSeconds = max(1, $ttlSeconds);
            return (bool)$this->r->setex($this->k($key), $ttlSeconds, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getJson(string $key): mixed
    {
        $v = $this->get($key);
        if ($v === null || $v === '') return null;
        $d = json_decode($v, true);
        return $d;
    }

    public function setJson(string $key, mixed $value, int $ttlSeconds): bool
    {
        $enc = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($enc === false) return false;
        return $this->set($key, $enc, $ttlSeconds);
    }

    /**
     * Flush only keys with our prefix (namespace-safe).
     * Returns number of keys deleted (best effort).
     */
    public function flushPrefix(int $maxScan = 5000): int
    {
        if (!$this->enabled()) return 0;

        $deleted = 0;
        $it = null;
        $pattern = $this->prefix . '*';

        try {
            while (true) {
                $keys = $this->r->scan($it, $pattern, min(1000, $maxScan));
                if ($keys === false) break;
                foreach ($keys as $k) {
                    $this->r->del($k);
                    $deleted++;
                    if ($deleted >= $maxScan) break 2;
                }
                if ($it === 0) break;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $deleted;
    }

    /**
     * Counts keys under prefix (sampled scan).
     */
    public function countByPrefix(int $maxScan = 2000): int
    {
        if (!$this->enabled()) return 0;

        $count = 0;
        $it = null;
        $pattern = $this->prefix . '*';

        try {
            while (true) {
                $keys = $this->r->scan($it, $pattern, min(1000, $maxScan));
                if ($keys === false) break;
                $count += count($keys);
                if ($count >= $maxScan) break;
                if ($it === 0) break;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $count;
    }
}
