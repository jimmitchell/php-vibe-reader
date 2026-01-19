<?php

namespace PhpRss\Cache;

/**
 * Cache interface for different cache backends.
 */
interface CacheInterface
{
    /**
     * Get a value from the cache.
     * 
     * @param string $key Cache key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Cached value or default
     */
    public function get(string $key, $default = null);

    /**
     * Store a value in the cache.
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null = use default)
     * @return bool True on success
     */
    public function set(string $key, $value, ?int $ttl = null): bool;

    /**
     * Delete a value from the cache.
     * 
     * @param string $key Cache key
     * @return bool True if key was deleted
     */
    public function delete(string $key): bool;

    /**
     * Check if a key exists in the cache.
     * 
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function has(string $key): bool;

    /**
     * Clear all cache entries with a given prefix.
     * 
     * @param string $prefix Key prefix
     * @return int Number of keys cleared
     */
    public function clear(string $prefix): int;
}
