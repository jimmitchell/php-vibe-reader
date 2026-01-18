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
     * Fetch feed content from a given URL.
     * 
     * Uses cURL to download feed content with appropriate headers and settings.
     * Handles redirects, SSL verification, and timeouts. Throws exceptions
     * on connection errors or non-200 HTTP responses.
     * 
     * @param string $url The feed URL to fetch
     * @return string The raw feed content (XML for RSS/Atom, JSON for JSON Feed)
     * @throws \Exception If the fetch fails or returns a non-200 HTTP status
     */
    public static function fetch(string $url): string
    {
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
