<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\Database;
use PhpRss\View;

/**
 * Controller for the main dashboard page.
 *
 * Handles rendering the dashboard view with user feeds and folder information.
 */
class DashboardController
{
    /**
     * Display the main dashboard page.
     *
     * Requires authentication. Loads all feeds for the current user, including
     * folder associations, and renders the dashboard template.
     *
     * @return void
     */
    public function index(): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $db = Database::getConnection();

        // Get all feeds for the user, including folder information
        $stmt = $db->prepare("
            SELECT f.*, fld.name as folder_name 
            FROM feeds f 
            LEFT JOIN folders fld ON f.folder_id = fld.id 
            WHERE f.user_id = ? 
            ORDER BY COALESCE(fld.sort_order, 999999) ASC, fld.name ASC, f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$user['id']]);
        $feeds = $stmt->fetchAll();

        View::render('dashboard', [
            'user' => $user,
            'feeds' => $feeds,
        ]);
    }
}
