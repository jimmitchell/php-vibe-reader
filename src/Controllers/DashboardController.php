<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\View;
use PhpRss\Database;
use PDO;

class DashboardController
{
    public function index(): void
    {
        Auth::requireAuth();
        
        $user = Auth::user();
        $db = Database::getConnection();

        // Get all feeds for the user
        $stmt = $db->prepare("SELECT * FROM feeds WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$user['id']]);
        $feeds = $stmt->fetchAll();

        View::render('dashboard', [
            'user' => $user,
            'feeds' => $feeds
        ]);
    }
}
