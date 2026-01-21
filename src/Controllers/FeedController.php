<?php

namespace PhpRss\Controllers;

use PDO;
use PhpRss\Auth;
use PhpRss\Config;
use PhpRss\Csrf;
use PhpRss\Database;
use PhpRss\FeedDiscovery;
use PhpRss\FeedFetcher;
use PhpRss\FeedParser;
use PhpRss\Logger;
use PhpRss\Response;
use PhpRss\Services\FeedService;

/**
 * Controller for handling feed-related operations.
 *
 * Manages feed CRUD operations, feed item retrieval, folder management,
 * OPML import/export, user preferences, and feed updates. Provides both
 * JSON API responses and handles feed fetching, parsing, and storage.
 */
class FeedController
{
    /**
     * Add a new feed to the user's feed list.
     *
     * Accepts either a direct feed URL or a website URL. If a website URL
     * is provided, attempts feed discovery. Validates the feed, checks for
     * duplicates, and creates/updates the feed record. Fetches initial feed
     * items after adding.
     *
     * POST parameter: 'url' - the feed URL or website URL
     *
     * @return void Outputs JSON with 'success' boolean and optional 'error' message
     */
    public function add(): void
    {
        Auth::requireAuth();

        // Validate CSRF token for state-changing operations
        Csrf::requireValid();

        $url = trim($_POST['url'] ?? '');
        if (empty($url)) {
            Response::error('URL is required', 400);

            return;
        }

        // Validate URL
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            Response::error('Invalid URL', 400);

            return;
        }

        try {
            $user = Auth::user();
            $db = Database::getConnection();

            // Try to fetch as feed first
            $feedUrl = $url;
            $content = null;
            $parsed = null;
            $feedType = null;
            $isFeed = false;

            // First, try the URL as-is (it might already be a feed)
            try {
                $content = FeedFetcher::fetch($url);
                // Check if it's actually a feed by trying to parse it
                $parsed = FeedParser::parse($url, $content);
                $feedType = FeedParser::detectFeedType($content);
                $isFeed = true;
            } catch (\Exception $e) {
                // Log the error for debugging
                Logger::debug("Direct feed parse failed for $url", ['url' => $url, 'error' => $e->getMessage()]);
                // Not a direct feed, will try discovery below
                $isFeed = false;
            }

            // If not a feed, try discovery
            if (! $isFeed) {
                try {
                    $discoveredFeeds = FeedDiscovery::discover($url);

                    if (empty($discoveredFeeds)) {
                        throw new \Exception("No feed found at this URL. The page doesn't contain feed links and common feed paths don't work. Please try providing a direct feed URL.");
                    }

                    // Use the first discovered feed
                    $feedUrl = $discoveredFeeds[0]['url'];
                    $content = FeedFetcher::fetch($feedUrl);
                    $parsed = FeedParser::parse($feedUrl, $content);
                    $feedType = FeedParser::detectFeedType($content);
                } catch (\Exception $e) {
                    // If discovery also fails, provide a helpful error
                    throw new \Exception("Could not find a feed at this URL: " . $e->getMessage());
                }
            }

            // Check if feed already exists for this user
            $stmt = $db->prepare("SELECT id FROM feeds WHERE user_id = ? AND url = ?");
            $stmt->execute([$user['id'], $feedUrl]);
            $existingFeed = $stmt->fetch();

            if ($existingFeed) {
                // Feed already exists, update it and use existing ID
                $feedId = $existingFeed['id'];
                $stmt = $db->prepare("
                    UPDATE feeds 
                    SET title = ?, feed_type = ?, description = ?, last_fetched = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $parsed['title'],
                    $feedType,
                    $parsed['description'],
                    $feedId,
                ]);
            } else {
                // Insert new feed (sort_order = next for this user)
                $stmt = $db->prepare("
                    INSERT INTO feeds (user_id, title, url, feed_type, description, last_fetched, sort_order)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, (SELECT COALESCE(MAX(sort_order),0)+1 FROM feeds WHERE user_id = ?))
                ");
                $stmt->execute([
                    $user['id'],
                    $parsed['title'],
                    $feedUrl, // Use discovered feed URL if found
                    $feedType,
                    $parsed['description'],
                    $user['id'],
                ]);

                $feedId = $db->lastInsertId();
            }

            // Insert feed items
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
                    $item['guid'],
                ]);
            }

            // Check if we got any items
            if (empty($parsed['items'])) {
                Response::error('Feed was parsed but contains no items. The feed might be empty or the format is not fully supported.', 400);

                return;
            }

            // Invalidate cache for user's feeds
            FeedService::invalidateUserCache($user['id']);

            Response::success([
                'feed_id' => $feedId,
                'feed_url' => $feedUrl,
                'item_count' => count($parsed['items']),
            ]);
        } catch (\Exception $e) {
            // Provide more helpful error messages
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'No feed found') !== false || strpos($errorMsg, 'Could not find') !== false) {
                // Keep the discovery error message
            } else {
                // For other errors, provide context
                $errorMsg = "Failed to add feed: " . $errorMsg;
            }
            // Log full error for debugging
            Logger::exception($e, ['url' => $url, 'error_msg' => $errorMsg]);
            Response::error($errorMsg, 400);
        }
    }

    /**
     * Get list of all feeds for the current user with counts.
     *
     * Returns JSON array of feeds with folder associations, item counts, and
     * unread counts. Dates are formatted for JSON (ISO 8601 UTC).
     *
     * @return void Outputs JSON array of feed data
     */
    public function list(): void
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
     * Returns feed items with read status. Respects user's hide_read_items
     * preference. Items are ordered by published date (newest first).
     *
     * @param array $params Route parameters including 'id' (feed ID)
     * @return void Outputs JSON array of feed items
     */
    public function getItems(array $params): void
    {
        Auth::requireAuth();

        $feedId = $params['id'] ?? null;
        if (! $feedId) {
            Response::error('Feed ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user using FeedService
        if (! FeedService::verifyFeedOwnership($feedId, $user['id'])) {
            Response::error('Feed not found', 404);

            return;
        }

        // Check if user wants to hide read items
        $hideReadItems = $_SESSION['hide_read_items'] ?? ($user['hide_read_items'] ?? true);

        // Get sort order preference (newest or oldest)
        $sortOrder = $_SESSION['item_sort_order'] ?? ($user['item_sort_order'] ?? 'newest');
        $sortDirection = ($sortOrder === 'oldest') ? 'ASC' : 'DESC';

        $sql = "
            SELECT fi.*, 
                   CASE WHEN ri.id IS NOT NULL THEN 1 ELSE 0 END as is_read
            FROM feed_items fi
            LEFT JOIN read_items ri ON ri.feed_item_id = fi.id AND ri.user_id = ?
            WHERE fi.feed_id = ?
        ";

        if ($hideReadItems) {
            $sql .= " AND ri.id IS NULL";
        }

        // Order by published date based on user preference (newest first by default)
        $sql .= " ORDER BY fi.published_at {$sortDirection}, fi.created_at {$sortDirection}";

        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id'], $feedId]);
        $items = $stmt->fetchAll();

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $items = array_map(function ($item) {
            return \PhpRss\Utils::formatDatesForJson($item);
        }, $items);

        Response::json($items);
    }

    /**
     * Get a single feed item by ID.
     *
     * Returns the full feed item data including feed title. Verifies that
     * the item belongs to the current user's feeds.
     *
     * @param array $params Route parameters including 'id' (item ID)
     * @return void Outputs JSON object with feed item data
     */
    public function getItem(array $params): void
    {
        Auth::requireAuth();

        $itemId = $params['id'] ?? null;
        if (! $itemId) {
            Response::error('Item ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify item belongs to user's feed; include feed title for untitled posts
        $stmt = $db->prepare("
            SELECT fi.*, f.title AS feed_title
            FROM feed_items fi
            JOIN feeds f ON fi.feed_id = f.id
            WHERE fi.id = ? AND f.user_id = ?
        ");
        $stmt->execute([$itemId, $user['id']]);
        $item = $stmt->fetch();

        if (! $item) {
            Response::error('Item not found', 404);

            return;
        }

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $item = \PhpRss\Utils::formatDatesForJson($item);

        Response::json($item);
    }

    /**
     * Mark a feed item as read for the current user.
     *
     * Creates a read_items record. Uses database-specific conflict handling
     * (ON CONFLICT DO NOTHING for PostgreSQL, INSERT OR IGNORE for SQLite).
     *
     * @param array $params Route parameters including 'id' (item ID)
     * @return void Outputs JSON with 'success' boolean
     */
    public function markAsRead(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $itemId = $params['id'] ?? null;
        if (! $itemId) {
            Response::error('Item ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify item belongs to user's feed
        $stmt = $db->prepare("
            SELECT fi.id
            FROM feed_items fi
            JOIN feeds f ON fi.feed_id = f.id
            WHERE fi.id = ? AND f.user_id = ?
        ");
        $stmt->execute([$itemId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Item not found', 404);

            return;
        }

        // Get feed ID for cache invalidation
        $stmt = $db->prepare("SELECT feed_id FROM feed_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $feedData = $stmt->fetch();
        $feedId = $feedData['feed_id'] ?? null;

        // Mark as read
        $dbType = Database::getDbType();
        $insertSql = $dbType === 'pgsql'
            ? "INSERT INTO read_items (user_id, feed_item_id) VALUES (?, ?) ON CONFLICT (user_id, feed_item_id) DO NOTHING"
            : "INSERT OR IGNORE INTO read_items (user_id, feed_item_id) VALUES (?, ?)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$user['id'], $itemId]);

        // Invalidate cache
        if ($feedId) {
            FeedService::invalidateFeedCache($feedId);
        }
        FeedService::invalidateUserCache($user['id']);

        Response::success();
    }

    /**
     * Mark a feed item as unread (remove from read_items).
     *
     * Deletes the read_items record for this user and item.
     *
     * @param array $params Route parameters including 'id' (item ID)
     * @return void Outputs JSON with 'success' boolean
     */
    public function markAsUnread(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $itemId = $params['id'] ?? null;
        if (! $itemId) {
            Response::error('Item ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify item belongs to user's feed and get feed ID
        $stmt = $db->prepare("
            SELECT fi.id, fi.feed_id
            FROM feed_items fi
            JOIN feeds f ON fi.feed_id = f.id
            WHERE fi.id = ? AND f.user_id = ?
        ");
        $stmt->execute([$itemId, $user['id']]);
        $itemData = $stmt->fetch();
        if (! $itemData) {
            Response::error('Item not found', 404);

            return;
        }
        $feedId = $itemData['feed_id'];

        // Mark as unread (delete from read_items)
        $stmt = $db->prepare("DELETE FROM read_items WHERE user_id = ? AND feed_item_id = ?");
        $stmt->execute([$user['id'], $itemId]);

        // Invalidate cache
        FeedService::invalidateFeedCache($feedId);
        FeedService::invalidateUserCache($user['id']);

        Response::success();
    }

    /**
     * Mark all items in a feed as read.
     *
     * Bulk inserts read_items records for all items in the specified feed.
     * Uses database-specific conflict handling.
     *
     * @param array $params Route parameters including 'id' (feed ID)
     * @return void Outputs JSON with 'success' boolean and 'count' of marked items
     */
    public function markAllAsRead(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $feedId = $params['id'] ?? null;
        if (! $feedId) {
            Response::error('Feed ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Feed not found', 404);

            return;
        }

        // Mark all items in the feed as read
        $dbType = Database::getDbType();
        $insertSql = $dbType === 'pgsql'
            ? "INSERT INTO read_items (user_id, feed_item_id) SELECT ?, fi.id FROM feed_items fi WHERE fi.feed_id = ? ON CONFLICT (user_id, feed_item_id) DO NOTHING"
            : "INSERT OR IGNORE INTO read_items (user_id, feed_item_id) SELECT ?, fi.id FROM feed_items fi WHERE fi.feed_id = ?";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$user['id'], $feedId]);

        // Invalidate cache
        FeedService::invalidateFeedCache($feedId);
        FeedService::invalidateUserCache($user['id']);

        Response::success(['count' => $stmt->rowCount()]);
    }

    /**
     * Fetch/update a feed by fetching latest content from the feed URL.
     *
     * Delegates to FeedFetcher::updateFeed() to fetch and parse the feed,
     * then update feed metadata and insert new items.
     *
     * @param array $params Route parameters including 'id' (feed ID)
     * @return void Outputs JSON with 'success' boolean
     */
    public function fetch(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $feedId = $params['id'] ?? null;
        if (! $feedId) {
            Response::error('Feed ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Feed not found', 404);

            return;
        }

        // Check if this is a manual refresh (force immediate) or should use background jobs
        // Check both GET (query params) and POST (body) for immediate parameter
        $forceImmediate = false;
        if (isset($_GET['immediate']) || isset($_POST['immediate'])) {
            $value = $_POST['immediate'] ?? $_GET['immediate'] ?? 'false';
            // Check for truthy values: 'true', '1', 'yes', 'on' (case-insensitive)
            $forceImmediate = in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true);
        }
        $useBackgroundJobs = Config::get('jobs.enabled', false) && ! $forceImmediate;

        if ($useBackgroundJobs) {
            // Queue the job for background processing
            $jobId = \PhpRss\Queue\JobQueue::push(
                \PhpRss\Queue\JobQueue::TYPE_FETCH_FEED,
                ['feed_id' => $feedId]
            );

            Response::success(['job_id' => $jobId, 'message' => 'Feed update queued']);
        } else {
            // Process synchronously (original behavior or forced immediate)
            if (FeedFetcher::updateFeed($feedId)) {
                // Invalidate cache when feed is updated
                FeedService::invalidateFeedCache($feedId);
                FeedService::invalidateUserCache($user['id']);
                Response::success();
            } else {
                Response::error('Failed to fetch feed', 500);
            }
        }
    }

    /**
     * Delete a feed from the user's feed list.
     *
     * Deletes the feed record. Database cascade will handle deletion of
     * associated feed_items and read_items.
     *
     * @param array $params Route parameters including 'id' (feed ID)
     * @return void Outputs JSON with 'success' boolean
     */
    public function delete(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $feedId = $params['id'] ?? null;
        if (! $feedId) {
            Response::error('Feed ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Feed not found', 404);

            return;
        }

        // Delete feed (cascade will handle feed_items and read_items)
        $stmt = $db->prepare("DELETE FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);

        // Invalidate cache
        FeedService::invalidateFeedCache($feedId);
        FeedService::invalidateUserCache($user['id']);

        Response::success();
    }

    /**
     * Toggle the hide_read_items user preference.
     *
     * Toggles the user's preference for hiding read items and updates
     * both the database and session.
     *
     * @return void Outputs JSON with 'success' boolean and 'hide_read_items' value
     */
    public function toggleHideRead(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        // Toggle the preference
        $currentValue = $_SESSION['hide_read_items'] ?? ($user['hide_read_items'] ?? true);
        $newValue = ! $currentValue;

        // Update database
        $stmt = $db->prepare("UPDATE users SET hide_read_items = ? WHERE id = ?");
        $stmt->execute([$newValue ? 1 : 0, $user['id']]);

        // Update session
        $_SESSION['hide_read_items'] = $newValue;

        Response::success(['hide_read_items' => $newValue]);
    }

    /**
     * Toggle the item_sort_order user preference between 'newest' and 'oldest'.
     *
     * Toggles the user's preference for sorting feed items by date (newest first
     * or oldest first) and updates both the database and session.
     *
     * @return void Outputs JSON with 'success' boolean and 'item_sort_order' value
     */
    public function toggleItemSortOrder(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        // Get current sort order preference
        $currentOrder = $_SESSION['item_sort_order'] ?? ($user['item_sort_order'] ?? 'newest');
        // Toggle between 'newest' and 'oldest'
        $newOrder = ($currentOrder === 'newest') ? 'oldest' : 'newest';

        // Update database
        $stmt = $db->prepare("UPDATE users SET item_sort_order = ? WHERE id = ?");
        $stmt->execute([$newOrder, $user['id']]);

        // Update session
        $_SESSION['item_sort_order'] = $newOrder;

        Response::success(['item_sort_order' => $newOrder]);
    }

    /**
     * Toggle the hide_feeds_with_no_unread user preference.
     *
     * Toggles the user's preference for hiding feeds with no unread items
     * and updates both the database and session.
     *
     * @return void Outputs JSON with 'success' boolean and 'hide_feeds_with_no_unread' value
     */
    public function toggleHideFeedsWithNoUnread(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        // Toggle the preference
        $currentValue = $_SESSION['hide_feeds_with_no_unread'] ?? ($user['hide_feeds_with_no_unread'] ?? false);
        $newValue = ! $currentValue;

        // Update database
        $stmt = $db->prepare("UPDATE users SET hide_feeds_with_no_unread = ? WHERE id = ?");
        $stmt->execute([$newValue ? 1 : 0, $user['id']]);

        // Update session
        $_SESSION['hide_feeds_with_no_unread'] = $newValue;

        Response::success(['hide_feeds_with_no_unread' => $newValue]);
    }

    /**
     * Toggle the dark_mode user preference.
     *
     * Toggles the user's theme preference between light and dark mode,
     * updating both the database and session.
     *
     * @return void Outputs JSON with 'success' boolean and 'dark_mode' value
     */
    public function toggleTheme(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        $currentValue = (bool)($_SESSION['dark_mode'] ?? $user['dark_mode'] ?? 0);
        $newValue = ! $currentValue;

        $stmt = $db->prepare("UPDATE users SET dark_mode = ? WHERE id = ?");
        $stmt->execute([$newValue ? 1 : 0, $user['id']]);

        $_SESSION['dark_mode'] = $newValue;

        Response::success(['dark_mode' => $newValue]);
    }

    /**
     * Update the sort order of feeds.
     *
     * Accepts a JSON array of feed IDs in the desired order and updates
     * the sort_order field for each feed.
     *
     * JSON body: { "order": [feed_id1, feed_id2, ...] }
     *
     * @return void Outputs JSON with 'success' boolean
     */
    public function reorderFeeds(): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $rawInput = file_get_contents('php://input');
        $input = \PhpRss\Utils::safeJsonDecode($rawInput !== false ? $rawInput : '', [], true);
        $order = $input['order'] ?? [];
        if (! is_array($order) || empty($order)) {
            Response::error('Order array required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        $stmt = $db->prepare("UPDATE feeds SET sort_order = ? WHERE id = ? AND user_id = ?");
        foreach ($order as $i => $id) {
            $stmt->execute([$i, (int)$id, $user['id']]);
        }

        Response::success();
    }

    /**
     * Get current user preferences.
     *
     * Returns timezone, default_theme_mode, and font_family from session
     * or database (session takes precedence).
     *
     * @return void Outputs JSON with user preferences
     */
    public function getPreferences(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        Response::success([
            'timezone' => $_SESSION['timezone'] ?? $user['timezone'] ?? 'UTC',
            'default_theme_mode' => $_SESSION['default_theme_mode'] ?? $user['default_theme_mode'] ?? 'system',
            'font_family' => $_SESSION['font_family'] ?? $user['font_family'] ?? 'system',
        ]);
    }

    /**
     * Update user preferences (timezone, theme mode, font family).
     *
     * Validates input values and updates database and session. Only
     * updates fields that are provided in the request.
     *
     * JSON body: { "timezone": "...", "default_theme_mode": "...", "font_family": "..." }
     *
     * @return void Outputs JSON with 'success' boolean
     */
    public function updatePreferences(): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $rawInput = file_get_contents('php://input');
        $input = \PhpRss\Utils::safeJsonDecode($rawInput !== false ? $rawInput : '', [], true);
        $timezone = $input['timezone'] ?? null;
        $defaultThemeMode = $input['default_theme_mode'] ?? null;
        $fontFamily = $input['font_family'] ?? null;

        $user = Auth::user();
        $db = Database::getConnection();

        $updates = [];
        $params = [];

        if ($timezone !== null) {
            // Validate timezone
            try {
                new \DateTimeZone($timezone);
                $updates[] = "timezone = ?";
                $params[] = $timezone;
            } catch (\Exception $e) {
                Response::error('Invalid timezone', 400);

                return;
            }
        }

        if ($defaultThemeMode !== null) {
            if (! in_array($defaultThemeMode, ['light', 'dark', 'system'])) {
                Response::error('Invalid theme mode', 400);

                return;
            }
            $updates[] = "default_theme_mode = ?";
            $params[] = $defaultThemeMode;
        }

        if ($fontFamily !== null) {
            $validFonts = ['system', 'Lato', 'Roboto', 'Noto Sans', 'Nunito', 'Mulish'];
            if (! in_array($fontFamily, $validFonts)) {
                Response::error('Invalid font family', 400);

                return;
            }
            $updates[] = "font_family = ?";
            $params[] = $fontFamily;
        }

        if (empty($updates)) {
            Response::error('No valid preferences to update', 400);

            return;
        }

        $params[] = $user['id'];
        $stmt = $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        // Update session
        if ($timezone !== null) {
            $_SESSION['timezone'] = $timezone;
        }
        if ($defaultThemeMode !== null) {
            $_SESSION['default_theme_mode'] = $defaultThemeMode;
        }
        if ($fontFamily !== null) {
            $_SESSION['font_family'] = $fontFamily;
        }

        Response::success();
    }

    /**
     * Get all folders for the current user.
     *
     * Returns folders ordered by sort_order and name.
     *
     * @return void Outputs JSON with 'success' boolean and 'folders' array
     */
    public function getFolders(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT * FROM folders 
            WHERE user_id = ? 
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute([$user['id']]);
        $folders = $stmt->fetchAll();

        Response::success(['folders' => $folders]);
    }

    /**
     * Create a new folder for organizing feeds.
     *
     * Validates that the folder name is unique for this user and creates
     * the folder with appropriate sort_order.
     *
     * JSON body: { "name": "Folder Name" }
     *
     * @return void Outputs JSON with 'success' boolean and 'folder' data or error
     */
    public function createFolder(): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $rawInput = file_get_contents('php://input');
        $input = \PhpRss\Utils::safeJsonDecode($rawInput !== false ? $rawInput : '', [], true);
        $name = trim($input['name'] ?? '');

        if (empty($name)) {
            Response::error('Folder name is required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Check if folder with same name already exists for this user
        $stmt = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND name = ?");
        $stmt->execute([$user['id'], $name]);
        if ($stmt->fetch()) {
            Response::error('Folder with this name already exists', 400);

            return;
        }

        // Get max sort_order for this user
        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM folders WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        $sortOrder = $result['next_order'] ?? 0;

        $stmt = $db->prepare("INSERT INTO folders (user_id, name, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $name, $sortOrder]);

        Response::success(['folder_id' => $db->lastInsertId()]);
    }

    /**
     * Update a folder's name.
     *
     * Validates the folder belongs to the user and that the new name is unique.
     *
     * @param array $params Route parameters including 'id' (folder ID)
     * JSON body: { "name": "New Folder Name" }
     * @return void Outputs JSON with 'success' boolean
     */
    public function updateFolder(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $folderId = $params['id'] ?? null;
        if (! $folderId) {
            Response::error('Folder ID required', 400);

            return;
        }

        $rawInput = file_get_contents('php://input');
        $input = \PhpRss\Utils::safeJsonDecode($rawInput !== false ? $rawInput : '', [], true);
        $name = trim($input['name'] ?? '');

        if (empty($name)) {
            Response::error('Folder name is required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify folder belongs to user
        $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Folder not found', 404);

            return;
        }

        // Check if another folder with same name exists
        $stmt = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND name = ? AND id != ?");
        $stmt->execute([$user['id'], $name, $folderId]);
        if ($stmt->fetch()) {
            Response::error('Folder with this name already exists', 400);

            return;
        }

        $stmt = $db->prepare("UPDATE folders SET name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $folderId, $user['id']]);

        Response::success();
    }

    /**
     * Delete a folder.
     *
     * Deletes the folder. Feeds in the folder will have folder_id set to NULL
     * (handled by foreign key ON DELETE SET NULL).
     *
     * @param array $params Route parameters including 'id' (folder ID)
     * @return void Outputs JSON with 'success' boolean
     */
    public function deleteFolder(array $params): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $folderId = $params['id'] ?? null;
        if (! $folderId) {
            Response::error('Folder ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify folder belongs to user
        $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Folder not found', 404);

            return;
        }

        // Delete folder (feeds will have folder_id set to NULL due to ON DELETE SET NULL)
        $stmt = $db->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $user['id']]);

        Response::success();
    }

    /**
     * Assign a feed to a folder or remove it from a folder.
     *
     * Updates the feed's folder_id. If folder_id is null, removes the feed
     * from its current folder. Validates that both feed and folder belong
     * to the current user.
     *
     * JSON body: { "feed_id": 123, "folder_id": 456 } or { "feed_id": 123, "folder_id": null }
     *
     * @return void Outputs JSON with 'success' boolean
     */
    public function updateFeedFolder(): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        $rawInput = file_get_contents('php://input');
        $input = \PhpRss\Utils::safeJsonDecode($rawInput !== false ? $rawInput : '', [], true);
        $feedId = $input['feed_id'] ?? null;
        $folderId = $input['folder_id'] ?? null; // null means remove from folder

        if (! $feedId) {
            Response::error('Feed ID required', 400);

            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (! $stmt->fetch()) {
            Response::error('Feed not found', 404);

            return;
        }

        // If folder_id is provided, verify it belongs to user
        if ($folderId !== null) {
            $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$folderId, $user['id']]);
            if (! $stmt->fetch()) {
                Response::error('Folder not found', 404);

                return;
            }
        }

        $stmt = $db->prepare("UPDATE feeds SET folder_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $feedId, $user['id']]);

        // Invalidate cache when feed folder changes
        FeedService::invalidateFeedCache($feedId);
        FeedService::invalidateUserCache($user['id']);

        Response::success();
    }

    /**
     * Export user's feeds as OPML format.
     *
     * Generates OPML XML file containing all user feeds, organized by folders.
     * Sets appropriate headers for file download.
     *
     * @return void Outputs OPML XML and sets headers for download
     */
    public function exportOpml(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        // Get all feeds with folder information
        $stmt = $db->prepare("
            SELECT f.id, f.title, f.url, f.description, f.feed_type,
                   fld.name as folder_name
            FROM feeds f
            LEFT JOIN folders fld ON f.folder_id = fld.id
            WHERE f.user_id = ?
            ORDER BY COALESCE(fld.sort_order, 999999) ASC, fld.name ASC, f.sort_order ASC
        ");
        $stmt->execute([$user['id']]);
        $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group feeds by folder
        $foldersMap = [];
        $feedsWithoutFolder = [];
        foreach ($feeds as $feed) {
            if ($feed['folder_name']) {
                if (! isset($foldersMap[$feed['folder_name']])) {
                    $foldersMap[$feed['folder_name']] = [];
                }
                $foldersMap[$feed['folder_name']][] = $feed;
            } else {
                $feedsWithoutFolder[] = $feed;
            }
        }

        // Generate OPML XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<opml version="2.0">' . "\n";
        $xml .= '  <head>' . "\n";
        $xml .= '    <title>VibeReader Feeds Export</title>' . "\n";
        $xml .= '    <dateCreated>' . date('D, d M Y H:i:s T') . '</dateCreated>' . "\n";
        $xml .= '    <docs>Generated by ' . htmlspecialchars(\PhpRss\Version::getVersionString()) . '</docs>' . "\n";
        $xml .= '  </head>' . "\n";
        $xml .= '  <body>' . "\n";

        // Add feeds without folders
        foreach ($feedsWithoutFolder as $feed) {
            $xml .= '    <outline type="rss" text="' . htmlspecialchars($feed['title']) . '"';
            if ($feed['description']) {
                $xml .= ' description="' . htmlspecialchars($feed['description']) . '"';
            }
            $xml .= ' xmlUrl="' . htmlspecialchars($feed['url']) . '"/>' . "\n";
        }

        // Add folders with feeds
        foreach ($foldersMap as $folderName => $folderFeeds) {
            $xml .= '    <outline text="' . htmlspecialchars($folderName) . '">' . "\n";
            foreach ($folderFeeds as $feed) {
                $xml .= '      <outline type="rss" text="' . htmlspecialchars($feed['title']) . '"';
                if ($feed['description']) {
                    $xml .= ' description="' . htmlspecialchars($feed['description']) . '"';
                }
                $xml .= ' xmlUrl="' . htmlspecialchars($feed['url']) . '"/>' . "\n";
            }
            $xml .= '    </outline>' . "\n";
        }

        $xml .= '  </body>' . "\n";
        $xml .= '</opml>';

        // Output as file download
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="vibereader-feeds.opml"');
        echo $xml;
    }

    /**
     * Import feeds from an OPML file.
     *
     * Parses uploaded OPML XML file, extracts feed URLs and folder structure,
     * and adds feeds to the user's feed list. Handles nested folder structures
     * and creates folders as needed. Uses output buffering to ensure only
     * JSON is returned (no PHP errors/warnings in response).
     *
     * POST file upload: 'opml_file' - the OPML XML file
     *
     * @return void Outputs JSON with 'success' boolean, 'added' count, and 'errors' array
     */
    public function importOpml(): void
    {
        Auth::requireAuth();

        // Validate CSRF token
        Csrf::requireValid();

        // Catch any PHP errors/warnings
        ob_start();

        try {
            // Validate file upload
            if (! isset($_FILES['opml_file'])) {
                ob_end_clean();
                Response::error('No file uploaded', 400);

                return;
            }

            // Check file size (max 5MB)
            if ($_FILES['opml_file']['size'] > 5 * 1024 * 1024) {
                ob_end_clean();
                Response::error('File too large. Maximum size is 5MB', 400);

                return;
            }

            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['opml_file']['tmp_name']);
            finfo_close($finfo);

            $allowedMimes = ['application/xml', 'text/xml', 'application/octet-stream'];
            if (! in_array($mimeType, $allowedMimes)) {
                // Check file extension as fallback
                $ext = strtolower(pathinfo($_FILES['opml_file']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'opml' && $ext !== 'xml') {
                    ob_end_clean();
                    Response::error('Invalid file type. Only OPML/XML files are allowed', 400);

                    return;
                }
            }

            if ($_FILES['opml_file']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'No file uploaded';
                if (isset($_FILES['opml_file']['error'])) {
                    switch ($_FILES['opml_file']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $errorMsg = 'File too large';

                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $errorMsg = 'File partially uploaded';

                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $errorMsg = 'No file uploaded';

                            break;
                        case UPLOAD_ERR_NO_TMP_DIR:
                            $errorMsg = 'Missing temporary folder';

                            break;
                        case UPLOAD_ERR_CANT_WRITE:
                            $errorMsg = 'Failed to write file to disk';

                            break;
                        case UPLOAD_ERR_EXTENSION:
                            $errorMsg = 'File upload stopped by extension';

                            break;
                    }
                }
                ob_end_clean();
                Response::error($errorMsg, 400);

                return;
            }

            $file = $_FILES['opml_file']['tmp_name'];
            $content = file_get_contents($file);

            if ($content === false) {
                ob_end_clean();
                Response::error('Could not read uploaded file', 500);

                return;
            }

            // Parse OPML XML
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);

            if ($xml === false) {
                $libxmlErrors = libxml_get_errors();
                libxml_clear_errors();
                ob_end_clean();
                $errorMsg = 'Invalid OPML file';
                if (! empty($libxmlErrors)) {
                    $errorMsg .= ': ' . $libxmlErrors[0]->message;
                }
                ob_end_clean();
                Response::error($errorMsg, 400);

                return;
            }

            $user = Auth::user();
            $db = Database::getConnection();
            $addedCount = 0;
            $skippedCount = 0;
            $errors = [];

            // Function to recursively process outlines
            $processOutlines = function ($outlines, $folderId = null) use (&$processOutlines, $db, $user, &$addedCount, &$skippedCount, &$errors) {
                if (! $outlines) {
                    return;
                }

                foreach ($outlines as $outline) {
                    $attributes = $outline->attributes();

                    // Check if this outline has an xmlUrl (it's a feed)
                    $xmlUrl = isset($attributes['xmlUrl']) ? (string)$attributes['xmlUrl'] : null;

                    if ($xmlUrl) {
                        // This is a feed
                        $feedUrl = $xmlUrl;
                        $feedTitle = isset($attributes['text']) ? (string)$attributes['text'] : (isset($attributes['title']) ? (string)$attributes['title'] : 'Untitled Feed');

                        if (empty($feedTitle)) {
                            $feedTitle = 'Untitled Feed';
                        }

                        // Check if feed already exists
                        $stmt = $db->prepare("SELECT id FROM feeds WHERE user_id = ? AND url = ?");
                        $stmt->execute([$user['id'], $feedUrl]);
                        if ($stmt->fetch()) {
                            $skippedCount++;

                            continue;
                        }

                        // Try to discover and add the feed
                        try {
                            // Use the feed URL directly - FeedDiscovery is mainly for website URLs
                            // Since OPML already contains feed URLs (xmlUrl), we can use them directly
                            $content = FeedFetcher::fetch($feedUrl);
                            $parsed = FeedParser::parse($feedUrl, $content);

                            // Insert feed
                            $stmt = $db->prepare("
                            INSERT INTO feeds (user_id, folder_id, title, url, feed_type, description, sort_order) 
                            VALUES (?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM feeds WHERE user_id = ?))
                        ");
                            $stmt->execute([
                                $user['id'],
                                $folderId,
                                $parsed['title'] ?: $feedTitle,
                                $feedUrl,
                                FeedParser::detectFeedType($content),
                                $parsed['description'] ?? null,
                                $user['id'],
                            ]);

                            $addedCount++;
                        } catch (\Exception $e) {
                            $errors[] = "Failed to add feed '$feedTitle': " . $e->getMessage();
                            $skippedCount++;
                        }
                    } else {
                        // This might be a folder (category) - check if it has text/title but no xmlUrl
                        $folderName = null;
                        if (isset($attributes['text'])) {
                            $folderName = (string)$attributes['text'];
                        } elseif (isset($attributes['title'])) {
                            $folderName = (string)$attributes['title'];
                        }

                        // Check if this outline has children (nested outlines)
                        $hasChildren = isset($outline->outline) && count($outline->outline) > 0;

                        $currentFolderId = $folderId;

                        // If it has a name and children, treat it as a folder
                        if ($folderName && $hasChildren) {
                            // Check if folder exists, create if not
                            $stmt = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND name = ?");
                            $stmt->execute([$user['id'], $folderName]);
                            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($folder) {
                                $currentFolderId = $folder['id'];
                            } else {
                                // Create folder
                                $stmt = $db->prepare("
                                INSERT INTO folders (user_id, name, sort_order) 
                                VALUES (?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM folders WHERE user_id = ?))
                            ");
                                $stmt->execute([$user['id'], $folderName, $user['id']]);
                                $currentFolderId = $db->lastInsertId();
                            }
                        }

                        // Process children if they exist
                        if ($hasChildren) {
                            $processOutlines($outline->outline, $currentFolderId);
                        }
                    }
                }
            };

            // Process top-level outlines
            if (isset($xml->body->outline)) {
                // Handle both single outline and multiple outlines
                $outlines = $xml->body->outline;
                if (count($outlines) > 0) {
                    $processOutlines($outlines);
                }
            }

            ob_end_clean();
            Response::success([
                'added' => $addedCount,
                'skipped' => $skippedCount,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            $output = ob_get_clean();
            Logger::exception($e, ['output_buffer' => $output]);
            Response::error('Import failed: ' . $e->getMessage(), 500);
        } catch (\Error $e) {
            $output = ob_get_clean();
            Logger::error("OPML Import Fatal Error: " . $e->getMessage(), ['output_buffer' => $output, 'file' => $e->getFile(), 'line' => $e->getLine()]);
            Response::error('Import failed: ' . $e->getMessage(), 500);
        }
    }
}
