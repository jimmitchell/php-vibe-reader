<?php

namespace PhpRss\Services;

use PhpRss\Database;
use PDO;

/**
 * Feed service for business logic and data access.
 * 
 * Centralizes feed-related operations to reduce code duplication
 * between ApiController and FeedController. This service layer
 * contains reusable queries and business logic.
 */
class FeedService
{
    /**
     * Get all feeds for a user with counts and folder information.
     * 
     * @param int $userId The user ID
     * @param bool $hideNoUnread Whether to hide feeds with no unread items
     * @return array Array of feeds with item counts and folder data
     */
    public static function getFeedsForUser(int $userId, bool $hideNoUnread = false): array
    {
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
        $stmt->execute([$userId, $userId]);
        $feeds = $stmt->fetchAll();

        // Filter out feeds with no unread items if preference is enabled
        if ($hideNoUnread) {
            $feeds = array_filter($feeds, function($feed) {
                return ($feed['unread_count'] ?? 0) > 0;
            });
            // Re-index array after filtering
            $feeds = array_values($feeds);
        }

        return $feeds;
    }

    /**
     * Verify that a feed belongs to a user.
     * 
     * @param int $feedId The feed ID
     * @param int $userId The user ID
     * @return bool True if feed belongs to user, false otherwise
     */
    public static function verifyFeedOwnership(int $feedId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Verify that a feed item belongs to a user's feed.
     * 
     * @param int $itemId The feed item ID
     * @param int $userId The user ID
     * @return bool True if item belongs to user's feed, false otherwise
     */
    public static function verifyItemOwnership(int $itemId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT fi.id
            FROM feed_items fi
            JOIN feeds f ON fi.feed_id = f.id
            WHERE fi.id = ? AND f.user_id = ?
        ");
        $stmt->execute([$itemId, $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Verify that a folder belongs to a user.
     * 
     * @param int $folderId The folder ID
     * @param int $userId The user ID
     * @return bool True if folder belongs to user, false otherwise
     */
    public static function verifyFolderOwnership(int $folderId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $userId]);
        return $stmt->fetch() !== false;
    }
}
