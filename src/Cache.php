<?php

namespace PhpRss;

/**
 * Caching layer for application data.
 *
 * Provides caching functionality with support for both file-based (SQLite-compatible)
 * and Redis backends. Automatically handles serialization, expiration, and cache invalidation.
 *
 * Cache keys follow the pattern: "prefix:key" (e.g., "feed_counts:123")
 */
class Cache
{
    /** @var Cache\CacheInterface|null Cache implementation instance */
    private static ?Cache\CacheInterface $instance = null;

    /**
     * Get the cache instance.
     *
     * Initializes the cache backend based on configuration (file-based or Redis).
     * Falls back to file cache if Redis is unavailable or disabled.
     *
     * @return Cache\CacheInterface Cache implementation
     */
    private static function getInstance(): Cache\CacheInterface
    {
        if (self::$instance === null) {
            // Check if caching is enabled
            if (! Config::get('cache.enabled', true)) {
                // Return a no-op cache if disabled
                self::$instance = new Cache\NullCache();

                return self::$instance;
            }

            $driver = Config::get('cache.driver', 'file');

            if ($driver === 'redis' && extension_loaded('redis')) {
                try {
                    self::$instance = new Cache\RedisCache();
                } catch (\Exception $e) {
                    // Fallback to file cache if Redis fails
                    Logger::warning("Redis cache failed, falling back to file cache", [
                        'error' => $e->getMessage(),
                    ]);
                    self::$instance = new Cache\FileCache();
                }
            } else {
                self::$instance = new Cache\FileCache();
            }
        }

        return self::$instance;
    }

    /**
     * Get a value from the cache.
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Cached value or default
     */
    public static function get(string $key, $default = null)
    {
        return self::getInstance()->get($key, $default);
    }

    /**
     * Store a value in the cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null = use default)
     * @return bool True on success
     */
    public static function set(string $key, $value, ?int $ttl = null): bool
    {
        return self::getInstance()->set($key, $value, $ttl);
    }

    /**
     * Delete a value from the cache.
     *
     * @param string $key Cache key
     * @return bool True if key was deleted
     */
    public static function delete(string $key): bool
    {
        return self::getInstance()->delete($key);
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public static function has(string $key): bool
    {
        return self::getInstance()->has($key);
    }

    /**
     * Clear all cache entries with a given prefix.
     *
     * @param string $prefix Key prefix (e.g., "feed_counts")
     * @return int Number of keys cleared
     */
    public static function clear(string $prefix): int
    {
        return self::getInstance()->clear($prefix);
    }

    /**
     * Invalidate cache for a specific feed.
     *
     * Clears all cache entries related to a feed (counts, metadata, etc.).
     *
     * @param int $feedId Feed ID
     * @return void
     */
    public static function invalidateFeed(int $feedId): void
    {
        self::delete("feed_counts:{$feedId}");
        self::delete("feed_metadata:{$feedId}");
        self::clear("user_feeds:"); // Clear user feed lists (they contain counts)
    }

    /**
     * Invalidate cache for a user's feeds.
     *
     * Clears cached feed lists for a specific user.
     *
     * @param int $userId User ID
     * @return void
     */
    public static function invalidateUserFeeds(int $userId): void
    {
        self::delete("user_feeds:{$userId}");
        self::delete("user_feeds:{$userId}:hide_no_unread");
    }

    /**
     * Get or remember a value from cache.
     *
     * If the key exists, returns cached value. Otherwise, executes callback,
     * caches the result, and returns it.
     *
     * If caching is disabled, always executes callback and returns result without caching.
     *
     * @param string $key Cache key
     * @param callable $callback Callback to generate value if not cached
     * @param int|null $ttl Time to live in seconds
     * @return mixed Cached or generated value
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null)
    {
        // If caching is disabled, just execute callback
        if (! Config::get('cache.enabled', true)) {
            return $callback();
        }

        if (self::has($key)) {
            return self::get($key);
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }
}
