<?php
/**
 * Migration script to add hide_feeds_with_no_unread column to users table.
 * 
 * This script checks if the column exists and adds it if it doesn't.
 * It can be safely run multiple times.
 * 
 * Usage:
 *   php scripts/migrate_add_hide_feeds_no_unread.php
 *   
 * Or with Docker:
 *   docker-compose exec vibereader php scripts/migrate_add_hide_feeds_no_unread.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpRss\Database;

echo "Checking for hide_feeds_with_no_unread column...\n";

// Initialize database
Database::init();
$db = Database::getConnection();
$dbType = Database::getDbType();

// Check if column exists
$columnExists = false;

if ($dbType === 'pgsql') {
    // PostgreSQL: query information_schema
    $stmt = $db->prepare("
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_name = 'users' AND column_name = 'hide_feeds_with_no_unread'
    ");
    $stmt->execute();
    $columnExists = $stmt->fetchColumn() !== false;
} else {
    // SQLite: query PRAGMA table_info
    $stmt = $db->prepare("PRAGMA table_info(users)");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        if ($col['name'] === 'hide_feeds_with_no_unread') {
            $columnExists = true;
            break;
        }
    }
}

if ($columnExists) {
    echo "Column hide_feeds_with_no_unread already exists. No migration needed.\n";
} else {
    echo "Adding hide_feeds_with_no_unread column to users table...\n";
    try {
        $db->exec("ALTER TABLE users ADD COLUMN hide_feeds_with_no_unread INTEGER DEFAULT 0");
        echo "✓ Migration complete! Column hide_feeds_with_no_unread has been added.\n";
    } catch (PDOException $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Done!\n";