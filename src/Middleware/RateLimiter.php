<?php

namespace PhpRss\Middleware;

use PhpRss\Config;
use PhpRss\Database;
use PhpRss\Response;

/**
 * Rate limiting middleware.
 * 
 * Prevents abuse by limiting the number of requests per time window.
 * Uses database for tracking rate limits (can be replaced with Redis for better performance).
 */
class RateLimiter
{
    /**
     * Check if request is within rate limit.
     * 
     * @param string $key Rate limit key (e.g., 'login:127.0.0.1' or 'api:user:123')
     * @param int $maxRequests Maximum requests allowed
     * @param int $window Time window in seconds
     * @return bool True if within limit, false if exceeded
     */
    public static function check(string $key, int $maxRequests, int $window): bool
    {
        if (!Config::get('rate_limiting.enabled', true)) {
            return true; // Rate limiting disabled
        }

        $db = Database::getConnection();
        $dbType = Database::getDbType();
        
        // Ensure rate_limits table exists
        self::ensureTableExists($db, $dbType);

        $now = time();
        $windowStart = $now - $window;

        // Clean up old entries (older than window)
        $cleanupSql = "DELETE FROM rate_limits WHERE created_at < ?";
        $stmt = $db->prepare($cleanupSql);
        $stmt->execute([$windowStart]);

        // Get current count for this key in the time window (sliding window)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM rate_limits 
            WHERE rate_key = ? AND created_at >= ?
        ");
        $stmt->execute([$key, $windowStart]);
        $result = $stmt->fetch();

        $count = (int)($result['count'] ?? 0);

        if ($count >= $maxRequests) {
            return false; // Rate limit exceeded
        }

        // Record this request (each request gets its own record)
        $insertSql = "INSERT INTO rate_limits (rate_key, window_start, created_at) VALUES (?, ?, ?)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$key, $windowStart, $now]);

        return true; // Within limit
    }

    /**
     * Check rate limit and return 429 if exceeded.
     * 
     * @param string $key Rate limit key
     * @param int $maxRequests Maximum requests
     * @param int $window Time window in seconds
     * @param string $message Error message
     * @return bool True if allowed, exits with 429 if exceeded
     */
    public static function require(string $key, int $maxRequests, int $window, string $message = 'Rate limit exceeded'): bool
    {
        if (!self::check($key, $maxRequests, $window)) {
            Response::error($message, 429);
            exit;
        }
        return true;
    }

    /**
     * Get remaining requests for a rate limit key.
     * 
     * @param string $key Rate limit key
     * @param int $maxRequests Maximum requests allowed
     * @param int $window Time window in seconds
     * @return int Remaining requests
     */
    public static function getRemaining(string $key, int $maxRequests, int $window): int
    {
        $db = Database::getConnection();
        
        self::ensureTableExists($db, Database::getDbType());

        $now = time();
        // Use same window calculation as check() - sliding window based on created_at
        $windowStart = $now - $window;

        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM rate_limits 
            WHERE rate_key = ? AND created_at >= ?
        ");
        $stmt->execute([$key, $windowStart]);
        $result = $stmt->fetch();

        $count = (int)($result['count'] ?? 0);
        return max(0, $maxRequests - $count);
    }

    /**
     * Ensure rate_limits table exists in the database.
     * 
     * @param \PDO $db Database connection
     * @param string $dbType Database type ('pgsql' or 'sqlite')
     * @return void
     */
    private static function ensureTableExists(\PDO $db, string $dbType): void
    {
        if ($dbType === 'pgsql') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id SERIAL PRIMARY KEY,
                    rate_key VARCHAR(255) NOT NULL,
                    window_start INTEGER NOT NULL,
                    created_at INTEGER NOT NULL
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_key_window ON rate_limits(rate_key, window_start)");
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    rate_key TEXT NOT NULL,
                    window_start INTEGER NOT NULL,
                    created_at INTEGER NOT NULL
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_key_window ON rate_limits(rate_key, window_start)");
        }
    }
}
