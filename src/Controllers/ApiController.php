<?php

namespace PhpRss\Controllers;

use PDO;
use PhpRss\Auth;
use PhpRss\Csrf;
use PhpRss\Database;
use PhpRss\Response;
use PhpRss\Services\FeedService;

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

        $user = Auth::user();
        $hideFeedsWithNoUnread = $_SESSION['hide_feeds_with_no_unread'] ?? ($user['hide_feeds_with_no_unread'] ?? false);

        // Use FeedService to get feeds (eliminates code duplication)
        $feeds = FeedService::getFeedsForUser($user['id'], $hideFeedsWithNoUnread);

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $feeds = array_map(function ($feed) {
            return \PhpRss\Utils::formatDatesForJson($feed);
        }, $feeds);

        Response::json($feeds);
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
        // Validate CSRF token for API endpoints
        Csrf::requireValid();

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

        $query = trim($_GET['q'] ?? '');
        if (empty($query)) {
            Response::json([]);

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
            $searchPattern,
        ]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format dates for JSON
        $results = array_map(function ($item) {
            return \PhpRss\Utils::formatDatesForJson($item);
        }, $results);

        Response::json($results);
    }

    /**
     * Get application version information.
     *
     * Returns the application name and version number.
     *
     * @return void Outputs JSON with version information
     */
    public function getVersion(): void
    {
        Response::json([
            'app_name' => \PhpRss\Version::getAppName(),
            'version' => \PhpRss\Version::getVersion(),
            'version_string' => \PhpRss\Version::getVersionString(),
        ]);
    }

    /**
     * Get job queue statistics.
     *
     * Returns statistics about jobs in the queue (pending, processing, completed, failed).
     * Requires authentication.
     *
     * @return void Outputs JSON with job statistics
     */
    public function getJobStats(): void
    {
        Auth::requireAuth();

        $stats = \PhpRss\Queue\JobQueue::getStats();
        Response::json($stats);
    }

    /**
     * Queue a cleanup job for feed items.
     *
     * Queues a background job to clean up old feed items based on retention settings.
     * Requires authentication and CSRF token.
     *
     * @return void Outputs JSON with job ID
     */
    public function queueCleanup(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $feedId = isset($_POST['feed_id']) ? (int)$_POST['feed_id'] : null;
        $retentionDays = isset($_POST['retention_days']) ? (int)$_POST['retention_days'] : null;
        $retentionCount = isset($_POST['retention_count']) ? (int)$_POST['retention_count'] : null;

        $jobId = \PhpRss\Queue\JobQueue::push(
            \PhpRss\Queue\JobQueue::TYPE_CLEANUP_ITEMS,
            [
                'feed_id' => $feedId,
                'retention_days' => $retentionDays,
                'retention_count' => $retentionCount,
            ]
        );

        Response::success(['job_id' => $jobId, 'message' => 'Cleanup job queued']);
    }
}
