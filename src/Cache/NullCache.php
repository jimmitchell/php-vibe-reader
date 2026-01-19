<?php

namespace PhpRss\Cache;

/**
 * Null cache implementation (no-op).
 * 
 * Used when caching is disabled. All operations are no-ops that return
 * default values or false.
 */
class NullCache implements CacheInterface
{
    /**
     * Get a value from the cache (always returns default).
     * 
     * @param string $key Cache key
     * @param mixed $default Default value
     * @return mixed Always returns default
     */
    public function get(string $key, $default = null)
    {
        return $default;
    }

    /**
     * Store a value in the cache (no-op).
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live
     * @return bool Always returns true (no-op)
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        return true;
    }

    /**
     * Delete a value from the cache (no-op).
     * 
     * @param string $key Cache key
     * @return bool Always returns false (no-op)
     */
    public function delete(string $key): bool
    {
        return false;
    }

    /**
     * Check if a key exists in the cache (always false).
     * 
     * @param string $key Cache key
     * @return bool Always returns false
     */
    public function has(string $key): bool
    {
        return false;
    }

    /**
     * Clear all cache entries with a given prefix (no-op).
     * 
     * @param string $prefix Key prefix
     * @return int Always returns 0
     */
    public function clear(string $prefix): int
    {
        return 0;
    }
}
