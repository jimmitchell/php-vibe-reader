<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\Database;
use PhpRss\FeedParser;
use PhpRss\FeedFetcher;
use PhpRss\FeedDiscovery;
use PDO;

class FeedController
{
    public function add(): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $url = $_POST['url'] ?? '';
        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'URL is required']);
            return;
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid URL']);
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
                error_log("Direct feed parse failed for $url: " . $e->getMessage());
                // Not a direct feed, will try discovery below
                $isFeed = false;
            }
            
            // If not a feed, try discovery
            if (!$isFeed) {
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
                    $feedId
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
                    $user['id']
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
                    $item['guid']
                ]);
            }

            // Check if we got any items
            if (empty($parsed['items'])) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'Feed was parsed but contains no items. The feed might be empty or the format is not fully supported.'
                ]);
                return;
            }
            
            echo json_encode(['success' => true, 'feed_id' => $feedId, 'feed_url' => $feedUrl, 'item_count' => count($parsed['items'])]);
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
            error_log("Feed add error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
    }

    public function list(): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $user = Auth::user();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT f.*, 
                   COUNT(fi.id) as item_count,
                   COUNT(CASE WHEN ri.id IS NULL THEN 1 END) as unread_count
            FROM feeds f
            LEFT JOIN feed_items fi ON f.id = fi.feed_id
            LEFT JOIN read_items ri ON ri.feed_item_id = fi.id AND ri.user_id = ?
            WHERE f.user_id = ?
            GROUP BY f.id
            ORDER BY f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$user['id'], $user['id']]);
        $feeds = $stmt->fetchAll();

        echo json_encode($feeds);
    }

    public function getItems(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $feedId = $params['id'] ?? null;
        if (!$feedId) {
            echo json_encode(['error' => 'Feed ID required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Feed not found']);
            return;
        }

        // Check if user wants to hide read items
        $hideReadItems = $_SESSION['hide_read_items'] ?? ($user['hide_read_items'] ?? true);
        
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
        
        $sql .= " ORDER BY fi.published_at DESC, fi.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id'], $feedId]);
        $items = $stmt->fetchAll();

        echo json_encode($items);
    }

    public function getItem(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $itemId = $params['id'] ?? null;
        if (!$itemId) {
            echo json_encode(['error' => 'Item ID required']);
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

        if (!$item) {
            echo json_encode(['error' => 'Item not found']);
            return;
        }

        echo json_encode($item);
    }

    public function markAsRead(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $itemId = $params['id'] ?? null;
        if (!$itemId) {
            echo json_encode(['success' => false, 'error' => 'Item ID required']);
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
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Item not found']);
            return;
        }

        // Mark as read
        $dbType = Database::getDbType();
        $insertSql = $dbType === 'pgsql'
            ? "INSERT INTO read_items (user_id, feed_item_id) VALUES (?, ?) ON CONFLICT (user_id, feed_item_id) DO NOTHING"
            : "INSERT OR IGNORE INTO read_items (user_id, feed_item_id) VALUES (?, ?)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$user['id'], $itemId]);

        echo json_encode(['success' => true]);
    }

    public function markAsUnread(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $itemId = $params['id'] ?? null;
        if (!$itemId) {
            echo json_encode(['success' => false, 'error' => 'Item ID required']);
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
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Item not found']);
            return;
        }

        // Mark as unread (delete from read_items)
        $stmt = $db->prepare("DELETE FROM read_items WHERE user_id = ? AND feed_item_id = ?");
        $stmt->execute([$user['id'], $itemId]);

        echo json_encode(['success' => true]);
    }

    public function markAllAsRead(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $feedId = $params['id'] ?? null;
        if (!$feedId) {
            echo json_encode(['success' => false, 'error' => 'Feed ID required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Feed not found']);
            return;
        }

        // Mark all items in the feed as read
        $dbType = Database::getDbType();
        $insertSql = $dbType === 'pgsql'
            ? "INSERT INTO read_items (user_id, feed_item_id) SELECT ?, fi.id FROM feed_items fi WHERE fi.feed_id = ? ON CONFLICT (user_id, feed_item_id) DO NOTHING"
            : "INSERT OR IGNORE INTO read_items (user_id, feed_item_id) SELECT ?, fi.id FROM feed_items fi WHERE fi.feed_id = ?";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$user['id'], $feedId]);

        echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
    }

    public function fetch(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $feedId = $params['id'] ?? null;
        if (!$feedId) {
            echo json_encode(['success' => false, 'error' => 'Feed ID required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Feed not found']);
            return;
        }

        if (FeedFetcher::updateFeed($feedId)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to fetch feed']);
        }
    }

    public function delete(array $params): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $feedId = $params['id'] ?? null;
        if (!$feedId) {
            echo json_encode(['success' => false, 'error' => 'Feed ID required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify feed belongs to user
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Feed not found']);
            return;
        }

        // Delete feed (cascade will handle feed_items and read_items)
        $stmt = $db->prepare("DELETE FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $user['id']]);

        echo json_encode(['success' => true]);
    }

    public function toggleHideRead(): void
    {
        Auth::requireAuth();
        
        header('Content-Type: application/json');
        
        $user = Auth::user();
        $db = Database::getConnection();

        // Toggle the preference
        $currentValue = $_SESSION['hide_read_items'] ?? ($user['hide_read_items'] ?? true);
        $newValue = !$currentValue;
        
        // Update database
        $stmt = $db->prepare("UPDATE users SET hide_read_items = ? WHERE id = ?");
        $stmt->execute([$newValue ? 1 : 0, $user['id']]);
        
        // Update session
        $_SESSION['hide_read_items'] = $newValue;

        echo json_encode(['success' => true, 'hide_read_items' => $newValue]);
    }

    public function toggleTheme(): void
    {
        Auth::requireAuth();

        header('Content-Type: application/json');

        $user = Auth::user();
        $db = Database::getConnection();

        $currentValue = (bool)($_SESSION['dark_mode'] ?? $user['dark_mode'] ?? 0);
        $newValue = !$currentValue;

        $stmt = $db->prepare("UPDATE users SET dark_mode = ? WHERE id = ?");
        $stmt->execute([$newValue ? 1 : 0, $user['id']]);

        $_SESSION['dark_mode'] = $newValue;

        echo json_encode(['success' => true, 'dark_mode' => $newValue]);
    }

    public function reorderFeeds(): void
    {
        Auth::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $order = $input['order'] ?? [];
        if (!is_array($order) || empty($order)) {
            echo json_encode(['success' => false, 'error' => 'Order array required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        $stmt = $db->prepare("UPDATE feeds SET sort_order = ? WHERE id = ? AND user_id = ?");
        foreach ($order as $i => $id) {
            $stmt->execute([$i, (int)$id, $user['id']]);
        }

        echo json_encode(['success' => true]);
    }

    public function getPreferences(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $user = Auth::user();
        echo json_encode([
            'success' => true,
            'timezone' => $_SESSION['timezone'] ?? $user['timezone'] ?? 'UTC',
            'default_theme_mode' => $_SESSION['default_theme_mode'] ?? $user['default_theme_mode'] ?? 'system'
        ]);
    }

    public function updatePreferences(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $timezone = $input['timezone'] ?? null;
        $defaultThemeMode = $input['default_theme_mode'] ?? null;

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
                echo json_encode(['success' => false, 'error' => 'Invalid timezone']);
                return;
            }
        }

        if ($defaultThemeMode !== null) {
            if (!in_array($defaultThemeMode, ['light', 'dark', 'system'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid theme mode']);
                return;
            }
            $updates[] = "default_theme_mode = ?";
            $params[] = $defaultThemeMode;
        }

        if (empty($updates)) {
            echo json_encode(['success' => false, 'error' => 'No valid preferences to update']);
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

        echo json_encode(['success' => true]);
    }
}
