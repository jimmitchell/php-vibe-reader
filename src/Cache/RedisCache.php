<?php

namespace PhpRss\Cache;

use PhpRss\Config;
use Redis;

/**
 * Redis cache implementation.
 *
 * Uses Redis for high-performance caching. Requires Redis PHP extension.
 */
class RedisCache implements CacheInterface
{
    /** @var Redis|null Redis connection */
    private ?Redis $redis = null;

    /** @var int Default TTL in seconds */
    private int $defaultTtl;

    /**
     * Initialize Redis cache.
     */
    public function __construct()
    {
        $this->defaultTtl = Config::get('cache.ttl', 300); // 5 minutes default

        try {
            $this->redis = new Redis();
            $host = Config::get('cache.redis.host', '127.0.0.1');
            $port = Config::get('cache.redis.port', 6379);
            $password = Config::get('cache.redis.password', null);

            if (! $this->redis->connect($host, $port)) {
                throw new \Exception("Failed to connect to Redis");
            }

            if ($password !== null) {
                $this->redis->auth($password);
            }

            // Select database (default 0)
            $database = Config::get('cache.redis.database', 0);
            $this->redis->select($database);
        } catch (\Exception $e) {
            // Fallback to file cache if Redis fails
            \PhpRss\Logger::warning("Redis cache initialization failed, falling back to file cache", [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get a value from the cache.
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Cached value or default
     */
    public function get(string $key, $default = null)
    {
        if ($this->redis === null) {
            return $default;
        }

        $value = $this->redis->get($key);

        if ($value === false) {
            return $default;
        }

        return json_decode($value, true) ?? $default;
    }

    /**
     * Store a value in the cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null = use default)
     * @return bool True on success
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        if ($this->redis === null) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $serialized = json_encode($value);

        return $this->redis->setex($key, $ttl, $serialized);
    }

    /**
     * Delete a value from the cache.
     *
     * @param string $key Cache key
     * @return bool True if key was deleted
     */
    public function delete(string $key): bool
    {
        if ($this->redis === null) {
            return false;
        }

        return $this->redis->del($key) > 0;
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        if ($this->redis === null) {
            return false;
        }

        return $this->redis->exists($key) > 0;
    }

    /**
     * Clear all cache entries with a given prefix.
     *
     * @param string $prefix Key prefix
     * @return int Number of keys cleared
     */
    public function clear(string $prefix): int
    {
        if ($this->redis === null) {
            return 0;
        }

        $keys = $this->redis->keys($prefix . '*');
        if (empty($keys)) {
            return 0;
        }

        return $this->redis->del($keys);
    }
}
