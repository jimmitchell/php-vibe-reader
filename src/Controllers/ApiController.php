<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\Database;
use PDO;

/**
 * API controller for JSON-based API endpoints.
 * 
 * Provides RESTful API endpoints for the frontend JavaScript application,
 * returning data in JSON format. Some methods delegate to FeedController
 * to avoid code duplication.
 */
class ApiController
{
    /**
     * Get all feeds for the current user with counts.
     * 
     * Returns JSON array of feeds including folder associations, item counts,
     * and unread counts. Dates are formatted for JSON (ISO 8601 UTC).
     * 
     * @return void Outputs JSON array of feed data
     */
    public function getFeeds(): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $user = Auth::user();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT f.*, 
                   fld.id as folder_id,
                   fld.name as folder_name,
                   fld.sort_order as folder_sort_order,
                   COUNT(fi.id) as item_count,
                   COUNT(CASE WHEN ri.id IS NULL THEN 1 END) as unread_count
            FROM feeds f
            LEFT JOIN folders fld ON f.folder_id = fld.id
            LEFT JOIN feed_items fi ON f.id = fi.feed_id
            LEFT JOIN read_items ri ON ri.feed_item_id = fi.id AND ri.user_id = ?
            WHERE f.user_id = ?
            GROUP BY f.id, fld.id, fld.name, fld.sort_order
            ORDER BY COALESCE(fld.sort_order, 999999) ASC, fld.name ASC, f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$user['id'], $user['id']]);
        $feeds = $stmt->fetchAll();

        // Filter out feeds with no unread items if preference is enabled
        $hideFeedsWithNoUnread = $_SESSION['hide_feeds_with_no_unread'] ?? ($user['hide_feeds_with_no_unread'] ?? false);
        if ($hideFeedsWithNoUnread) {
            $feeds = array_filter($feeds, function($feed) {
                return ($feed['unread_count'] ?? 0) > 0;
            });
            // Re-index array after filtering
            $feeds = array_values($feeds);
        }

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $feeds = array_map(function($feed) {
            return \PhpRss\Utils::formatDatesForJson($feed);
        }, $feeds);

        echo json_encode($feeds);
    }

    /**
     * Get feed items for a specific feed.
     * 
     * Delegates to FeedController::getItems() for consistency.
     * 
     * @param array $params Route parameters including 'id' (feed ID)
     * @return void
     */
    public function getFeedItems(array $params): void
    {
        $feedController = new \PhpRss\Controllers\FeedController();
        $feedController->getItems($params);
    }

    /**
     * Get a single feed item by ID.
     * 
     * Delegates to FeedController::getItem() for consistency.
     * 
     * @param array $params Route parameters including 'id' (item ID)
     * @return void
     */
    public function getItem(array $params): void
    {
        $feedController = new \PhpRss\Controllers\FeedController();
        $feedController->getItem($params);
    }

    /**
     * Mark a feed item as read.
     * 
     * Delegates to FeedController::markAsRead() for consistency.
     * 
     * @param array $params Route parameters including 'id' (item ID)
     * @return void
     */
    public function markAsRead(array $params): void
    {
        $feedController = new \PhpRss\Controllers\FeedController();
        $feedController->markAsRead($params);
    }

    /**
     * Search feed items across all user feeds.
     * 
     * Searches in title, content, summary, and author fields. Uses case-insensitive
     * matching (ILIKE for PostgreSQL, LIKE for SQLite). Returns up to 100 results
     * ordered by publication date. Dates are formatted for JSON.
     * 
     * Query parameter: 'q' - the search query string
     * 
     * @return void Outputs JSON array of matching feed items
     */
    public function searchItems(): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $query = trim($_GET['q'] ?? '');
        if (empty($query)) {
            echo json_encode([]);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Search in title, content, summary, and author
        // Use ILIKE for PostgreSQL (case-insensitive) or LIKE for SQLite
        $dbType = Database::getDbType();
        $likeOperator = $dbType === 'pgsql' ? 'ILIKE' : 'LIKE';
        
        $searchPattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        
        $stmt = $db->prepare("
            SELECT 
                fi.id,
                fi.title,
                fi.link,
                fi.content,
                fi.summary,
                fi.author,
                fi.published_at,
                f.id as feed_id,
                f.title as feed_title,
                CASE WHEN ri.id IS NULL THEN 0 ELSE 1 END as is_read
            FROM feed_items fi
            INNER JOIN feeds f ON fi.feed_id = f.id
            LEFT JOIN read_items ri ON ri.feed_item_id = fi.id AND ri.user_id = ?
            WHERE f.user_id = ?
            AND (
                fi.title {$likeOperator} ? OR
                fi.content {$likeOperator} ? OR
                fi.summary {$likeOperator} ? OR
                fi.author {$likeOperator} ?
            )
            ORDER BY fi.published_at DESC
            LIMIT 100
        ");
        
        $stmt->execute([
            $user['id'],
            $user['id'],
            $searchPattern,
            $searchPattern,
            $searchPattern,
            $searchPattern
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format dates for JSON
        $results = array_map(function($item) {
            return \PhpRss\Utils::formatDatesForJson($item);
        }, $results);

        echo json_encode($results);
    }
}
