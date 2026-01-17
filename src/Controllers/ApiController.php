<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\Database;
use PDO;

class ApiController
{
    public function getFeeds(): void
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

    public function getFeedItems(array $params): void
    {
        $feedController = new \PhpRss\Controllers\FeedController();
        $feedController->getItems($params);
    }

    public function getItem(array $params): void
    {
        $feedController = new \PhpRss\Controllers\FeedController();
        $feedController->getItem($params);
    }

    public function markAsRead(array $params): void
    {
        $feedController = new \PhpRss\Controllers\FeedController();
        $feedController->markAsRead($params);
    }
}
