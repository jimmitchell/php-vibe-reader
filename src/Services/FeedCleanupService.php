<?php

namespace PhpRss\Services;

use PDO;
use PhpRss\Cache;
use PhpRss\Config;
use PhpRss\Database;
use PhpRss\Logger;

/**
 * Service for cleaning up old feed items.
 *
 * Provides functionality to remove old feed items based on retention policies,
 * helping to keep the database size manageable while preserving recent items.
 */
class FeedCleanupService
{
    /**
     * Clean up old items for a specific feed or all feeds.
     *
     * Removes items older than the retention period, keeping only the most
     * recent N items per feed (if retention_count is set).
     *
     * @param int|null $feedId Feed ID to clean (null for all feeds)
     * @param int|null $retentionDays Number of days to keep items (null = use config)
     * @param int|null $retentionCount Maximum number of items to keep per feed (null = use config)
     * @return array Statistics about the cleanup operation
     */
    public static function cleanupItems(?int $feedId = null, ?int $retentionDays = null, ?int $retentionCount = null): array
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();

        // Get retention settings from config
        $retentionDays = $retentionDays ?? Config::get('feed.retention_days', 90);
        $retentionCount = $retentionCount ?? Config::get('feed.retention_count', null);

        $stats = [
            'feeds_processed' => 0,
            'items_deleted' => 0,
            'feeds' => [],
        ];

        // Get feeds to process
        if ($feedId) {
            $stmt = $db->prepare("SELECT id, user_id FROM feeds WHERE id = ?");
            $stmt->execute([$feedId]);
            $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->query("SELECT id, user_id FROM feeds");
            $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($feeds as $feed) {
            $feedStats = self::cleanupFeedItems(
                (int)$feed['id'],
                (int)$feed['user_id'],
                $retentionDays,
                $retentionCount,
                $dbType
            );

            $stats['feeds_processed']++;
            $stats['items_deleted'] += $feedStats['deleted'];
            $stats['feeds'][] = [
                'feed_id' => $feed['id'],
                'deleted' => $feedStats['deleted'],
            ];

            // Invalidate cache for this feed
            Cache::invalidateFeed($feed['id']);
        }

        // Invalidate user caches for affected users
        $userIds = array_unique(array_column($feeds, 'user_id'));
        foreach ($userIds as $userId) {
            Cache::invalidateUserFeeds($userId);
        }

        Logger::info("Feed cleanup completed", [
            'feeds_processed' => $stats['feeds_processed'],
            'items_deleted' => $stats['items_deleted'],
        ]);

        return $stats;
    }

    /**
     * Clean up items for a specific feed.
     *
     * @param int $feedId Feed ID
     * @param int $userId User ID (for cache invalidation)
     * @param int $retentionDays Number of days to keep items
     * @param int|null $retentionCount Maximum number of items to keep
     * @param string $dbType Database type ('sqlite' or 'pgsql')
     * @return array Statistics for this feed
     */
    private static function cleanupFeedItems(int $feedId, int $userId, int $retentionDays, ?int $retentionCount, string $dbType): array
    {
        $db = Database::getConnection();
        $deleted = 0;

        // First, delete items older than retention_days
        if ($dbType === 'pgsql') {
            $stmt = $db->prepare("
                DELETE FROM feed_items
                WHERE feed_id = ?
                AND published_at < CURRENT_TIMESTAMP - INTERVAL '{$retentionDays} days'
            ");
        } else {
            $stmt = $db->prepare("
                DELETE FROM feed_items
                WHERE feed_id = ?
                AND published_at < datetime('now', '-{$retentionDays} days')
            ");
        }

        $stmt->execute([$feedId]);
        $deleted += $stmt->rowCount();

        // If retention_count is set, keep only the most recent N items
        if ($retentionCount !== null && $retentionCount > 0) {
            // Get IDs of items to keep (most recent N)
            if ($dbType === 'pgsql') {
                $keepStmt = $db->prepare("
                    SELECT id FROM feed_items
                    WHERE feed_id = ?
                    ORDER BY published_at DESC, created_at DESC
                    LIMIT ?
                ");
            } else {
                $keepStmt = $db->prepare("
                    SELECT id FROM feed_items
                    WHERE feed_id = ?
                    ORDER BY published_at DESC, created_at DESC
                    LIMIT ?
                ");
            }

            $keepStmt->execute([$feedId, $retentionCount]);
            $keepIds = $keepStmt->fetchAll(PDO::FETCH_COLUMN);

            if (! empty($keepIds)) {
                // Delete items not in the keep list
                $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
                $deleteStmt = $db->prepare("
                    DELETE FROM feed_items
                    WHERE feed_id = ?
                    AND id NOT IN ({$placeholders})
                ");

                $params = array_merge([$feedId], $keepIds);
                $deleteStmt->execute($params);
                $deleted += $deleteStmt->rowCount();
            }
        }

        return ['deleted' => $deleted];
    }
}
