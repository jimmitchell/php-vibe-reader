<?php

namespace PhpRss;

use PDO;

/**
 * Feed fetching and update class.
 * 
 * Handles fetching feed content from URLs using cURL and updating
 * feed data in the database when new items are available.
 */
class FeedFetcher
{
    /**
     * Validate URL to prevent SSRF attacks.
     * 
     * Checks if the URL points to internal/private IP ranges that should not
     * be accessible from the server.
     * 
     * @param string $url The URL to validate
     * @return bool True if URL is safe, false if it's an internal/private IP
     * @throws \Exception If URL is invalid or points to private/internal IP
     */
    private static function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        
        if (!$parsed || !isset($parsed['host'])) {
            throw new \Exception("Invalid URL format");
        }

        $host = $parsed['host'];
        
        // Resolve hostname to IP
        $ip = gethostbyname($host);
        
        // If hostname couldn't be resolved, gethostbyname returns the hostname
        if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \Exception("Could not resolve hostname");
        }
        
        // Check for private/internal IP ranges
        $privateRanges = [
            '127.0.0.0/8',      // Loopback
            '10.0.0.0/8',       // Private
            '172.16.0.0/12',    // Private
            '192.168.0.0/16',   // Private
            '169.254.0.0/16',   // Link-local
            '::1',              // IPv6 loopback
            'fc00::/7',         // IPv6 private
            'fe80::/10',        // IPv6 link-local
        ];
        
        foreach ($privateRanges as $range) {
            if (self::ipInRange($ip, $range)) {
                throw new \Exception("Access to private/internal IP addresses is not allowed");
            }
        }
        
        // Check for localhost variations
        $localhostPatterns = ['localhost', '127.', '0.0.0.0', '::1'];
        foreach ($localhostPatterns as $pattern) {
            if (stripos($host, $pattern) !== false || stripos($ip, $pattern) !== false) {
                throw new \Exception("Access to localhost is not allowed");
            }
        }
    }
    
    /**
     * Check if an IP address is within a CIDR range.
     * 
     * @param string $ip The IP address to check
     * @param string $range The CIDR range (e.g., '192.168.0.0/16')
     * @return bool True if IP is in range
     */
    private static function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $mask) = explode('/', $range);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }
        
        // IPv6 support (simplified)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // For IPv6, use inet_pton for comparison
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $maskBytes = (int)$mask / 8;
            return substr($ipBin, 0, $maskBytes) === substr($subnetBin, 0, $maskBytes);
        }
        
        return false;
    }

    /**
     * Fetch feed content from a given URL.
     * 
     * Uses cURL to download feed content with appropriate headers and settings.
     * Handles redirects, SSL verification, and timeouts. Validates URL to prevent
     * SSRF attacks. Throws exceptions on connection errors or non-200 HTTP responses.
     * 
     * @param string $url The feed URL to fetch
     * @return string The raw feed content (XML for RSS/Atom, JSON for JSON Feed)
     * @throws \Exception If the fetch fails or returns a non-200 HTTP status
     */
    public static function fetch(string $url): string
    {
        // Validate URL to prevent SSRF
        self::validateUrl($url);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; VibeReader/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/rss+xml, application/atom+xml, application/json, text/xml, application/xml, text/html, */*'
            ],
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($content === false || !empty($error)) {
            throw new \Exception("Failed to fetch feed: $error");
        }

        if ($httpCode !== 200) {
            throw new \Exception("HTTP error: $httpCode");
        }

        return $content;
    }

    /**
     * Update a feed by fetching the latest content and storing new items.
     * 
     * Fetches the feed content, parses it, updates the feed metadata (title,
     * description, last_fetched timestamp), and inserts any new feed items
     * into the database. Uses database-specific conflict handling (ON CONFLICT
     * for PostgreSQL, INSERT OR IGNORE for SQLite).
     * 
     * @param int $feedId The ID of the feed to update
     * @return bool True if update was successful, false if feed not found or update failed
     */
    public static function updateFeed(int $feedId): bool
    {
        $db = Database::getConnection();
        
        // Get feed URL
        $stmt = $db->prepare("SELECT url, feed_type FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);
        $feed = $stmt->fetch();

        if (!$feed) {
            return false;
        }

        try {
            $content = self::fetch($feed['url']);
            $parsed = FeedParser::parse($feed['url'], $content);

            // Update feed metadata
            $stmt = $db->prepare("UPDATE feeds SET title = ?, description = ?, last_fetched = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$parsed['title'], $parsed['description'], $feedId]);

            // Insert or update feed items
            $dbType = Database::getDbType();
            $insertSql = $dbType === 'pgsql'
                ? "INSERT INTO feed_items (feed_id, title, link, content, summary, author, published_at, guid) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (feed_id, guid) DO NOTHING"
                : "INSERT OR IGNORE INTO feed_items (feed_id, title, link, content, summary, author, published_at, guid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            foreach ($parsed['items'] as $item) {
                $stmt = $db->prepare($insertSql);
                $stmt->execute([
                    $feedId,
                    $item['title'],
                    $item['link'],
                    $item['content'],
                    $item['summary'],
                    $item['author'],
                    $item['published_at'],
                    $item['guid']
                ]);
            }

            return true;
        } catch (\Exception $e) {
            error_log("Error updating feed $feedId: " . $e->getMessage());
            return false;
        }
    }
}
