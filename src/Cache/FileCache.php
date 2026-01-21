<?php

namespace PhpRss\Cache;

use PhpRss\Config;

/**
 * File-based cache implementation.
 *
 * Stores cache data in JSON files on the filesystem. Suitable for SQLite setups
 * and environments where Redis is not available.
 */
class FileCache implements CacheInterface
{
    /** @var string Cache directory path */
    private string $cacheDir;

    /** @var int Default TTL in seconds */
    private int $defaultTtl;

    /**
     * Initialize file cache.
     */
    public function __construct()
    {
        $this->cacheDir = Config::get('cache.file.path', __DIR__ . '/../../data/cache');
        $this->defaultTtl = Config::get('cache.ttl', 300); // 5 minutes default

        // Create cache directory if it doesn't exist
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get cache file path for a key.
     *
     * @param string $key Cache key
     * @return string File path
     */
    private function getFilePath(string $key): string
    {
        // Sanitize key for filesystem (replace invalid chars)
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);

        return $this->cacheDir . '/' . md5($key) . '.json';
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
        $file = $this->getFilePath($key);

        if (! file_exists($file)) {
            return $default;
        }

        $fileContent = file_get_contents($file);
        if ($fileContent === false) {
            return $default;
        }

        $data = \PhpRss\Utils::safeJsonDecode($fileContent, $default, true);

        if ($data === null) {
            return $default;
        }

        // Check expiration
        if (isset($data['expires']) && $data['expires'] < time()) {
            unlink($file);

            return $default;
        }

        return $data['value'] ?? $default;
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
        $file = $this->getFilePath($key);
        $ttl = $ttl ?? $this->defaultTtl;

        $data = [
            'key' => $key, // Store key for prefix-based clearing
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time(),
        ];

        $result = file_put_contents($file, json_encode($data), LOCK_EX);

        return $result !== false;
    }

    /**
     * Delete a value from the cache.
     *
     * @param string $key Cache key
     * @return bool True if key was deleted
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return false;
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (! file_exists($file)) {
            return false;
        }

        // Check expiration
        $fileContent = file_get_contents($file);
        if ($fileContent === false) {
            return false;
        }

        $data = \PhpRss\Utils::safeJsonDecode($fileContent, null, true);
        if ($data === null || (isset($data['expires']) && $data['expires'] < time())) {
            unlink($file);

            return false;
        }

        return true;
    }

    /**
     * Clear all cache entries with a given prefix.
     *
     * @param string $prefix Key prefix
     * @return int Number of keys cleared
     */
    public function clear(string $prefix): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.json');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            try {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $data = \PhpRss\Utils::safeJsonDecode($content, null, true);
                if ($data !== null && isset($data['key']) && strpos($data['key'], $prefix) === 0) {
                    if (unlink($file)) {
                        $count++;
                    }
                }
            } catch (\Exception $e) {
                // Skip files that can't be read
                continue;
            }
        }

        return $count;
    }
}
