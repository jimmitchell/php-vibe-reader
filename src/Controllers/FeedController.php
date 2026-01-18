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

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $feeds = array_map(function($feed) {
            return \PhpRss\Utils::formatDatesForJson($feed);
        }, $feeds);

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

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $items = array_map(function($item) {
            return \PhpRss\Utils::formatDatesForJson($item);
        }, $items);

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

        // Format dates for JSON (convert to ISO 8601 with UTC timezone)
        $item = \PhpRss\Utils::formatDatesForJson($item);

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
            'default_theme_mode' => $_SESSION['default_theme_mode'] ?? $user['default_theme_mode'] ?? 'system',
            'font_family' => $_SESSION['font_family'] ?? $user['font_family'] ?? 'system'
        ]);
    }

    public function updatePreferences(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
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

        if ($fontFamily !== null) {
            $validFonts = ['system', 'Lato', 'Roboto', 'Noto Sans', 'Nunito', 'Mulish'];
            if (!in_array($fontFamily, $validFonts)) {
                echo json_encode(['success' => false, 'error' => 'Invalid font family']);
                return;
            }
            $updates[] = "font_family = ?";
            $params[] = $fontFamily;
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
        if ($fontFamily !== null) {
            $_SESSION['font_family'] = $fontFamily;
        }

        echo json_encode(['success' => true]);
    }

    public function getFolders(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $user = Auth::user();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT * FROM folders 
            WHERE user_id = ? 
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute([$user['id']]);
        $folders = $stmt->fetchAll();

        echo json_encode(['success' => true, 'folders' => $folders]);
    }

    public function createFolder(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Folder name is required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Check if folder with same name already exists for this user
        $stmt = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND name = ?");
        $stmt->execute([$user['id'], $name]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Folder with this name already exists']);
            return;
        }

        // Get max sort_order for this user
        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM folders WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        $sortOrder = $result['next_order'] ?? 0;

        $stmt = $db->prepare("INSERT INTO folders (user_id, name, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $name, $sortOrder]);

        echo json_encode(['success' => true, 'folder_id' => $db->lastInsertId()]);
    }

    public function updateFolder(array $params): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $folderId = $params['id'] ?? null;
        if (!$folderId) {
            echo json_encode(['success' => false, 'error' => 'Folder ID required']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Folder name is required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify folder belongs to user
        $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            return;
        }

        // Check if another folder with same name exists
        $stmt = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND name = ? AND id != ?");
        $stmt->execute([$user['id'], $name, $folderId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Folder with this name already exists']);
            return;
        }

        $stmt = $db->prepare("UPDATE folders SET name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $folderId, $user['id']]);

        echo json_encode(['success' => true]);
    }

    public function deleteFolder(array $params): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $folderId = $params['id'] ?? null;
        if (!$folderId) {
            echo json_encode(['success' => false, 'error' => 'Folder ID required']);
            return;
        }

        $user = Auth::user();
        $db = Database::getConnection();

        // Verify folder belongs to user
        $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            return;
        }

        // Delete folder (feeds will have folder_id set to NULL due to ON DELETE SET NULL)
        $stmt = $db->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $user['id']]);

        echo json_encode(['success' => true]);
    }

    public function updateFeedFolder(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $feedId = $input['feed_id'] ?? null;
        $folderId = $input['folder_id'] ?? null; // null means remove from folder

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

        // If folder_id is provided, verify it belongs to user
        if ($folderId !== null) {
            $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$folderId, $user['id']]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Folder not found']);
                return;
            }
        }

        $stmt = $db->prepare("UPDATE feeds SET folder_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $feedId, $user['id']]);

        echo json_encode(['success' => true]);
    }

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
                if (!isset($foldersMap[$feed['folder_name']])) {
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

    public function importOpml(): void
    {
        Auth::requireAuth();
        
        // Ensure we output JSON even if there are errors
        header('Content-Type: application/json');
        
        // Catch any PHP errors/warnings
        ob_start();
        
        try {
            if (!isset($_FILES['opml_file']) || $_FILES['opml_file']['error'] !== UPLOAD_ERR_OK) {
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
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                return;
            }

            $file = $_FILES['opml_file']['tmp_name'];
            $content = file_get_contents($file);
            
            if ($content === false) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => 'Could not read uploaded file']);
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
                if (!empty($libxmlErrors)) {
                    $errorMsg .= ': ' . $libxmlErrors[0]->message;
                }
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                return;
            }

            $user = Auth::user();
            $db = Database::getConnection();
            $addedCount = 0;
            $skippedCount = 0;
            $errors = [];

            // Function to recursively process outlines
            $processOutlines = function($outlines, $folderId = null) use (&$processOutlines, $db, $user, &$addedCount, &$skippedCount, &$errors) {
            if (!$outlines) {
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
                            $user['id']
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
            echo json_encode([
                'success' => true,
                'added' => $addedCount,
                'skipped' => $skippedCount,
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            $output = ob_get_clean();
            error_log("OPML Import Error: " . $e->getMessage());
            error_log("Output buffer: " . $output);
            echo json_encode([
                'success' => false,
                'error' => 'Import failed: ' . $e->getMessage()
            ]);
        } catch (\Error $e) {
            $output = ob_get_clean();
            error_log("OPML Import Fatal Error: " . $e->getMessage());
            error_log("Output buffer: " . $output);
            echo json_encode([
                'success' => false,
                'error' => 'Import failed: ' . $e->getMessage()
            ]);
        }
    }
}
