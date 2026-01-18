<?php
/**
 * Migration script to add item_sort_order column to users table.
 * 
 * This script checks if the column exists and adds it if it doesn't.
 * It can be safely run multiple times.
 * 
 * Usage:
 *   php scripts/migrate_add_item_sort_order.php
 *   
 * Or with Docker:
 *   docker-compose exec vibereader php scripts/migrate_add_item_sort_order.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpRss\Database;

echo "Checking for item_sort_order column...\n";

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
        WHERE table_name = 'users' AND column_name = 'item_sort_order'
    ");
    $stmt->execute();
    $columnExists = $stmt->fetchColumn() !== false;
} else {
    // SQLite: query PRAGMA table_info
    $stmt = $db->prepare("PRAGMA table_info(users)");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        if ($col['name'] === 'item_sort_order') {
            $columnExists = true;
            break;
        }
    }
}

if ($columnExists) {
    echo "Column item_sort_order already exists. No migration needed.\n";
} else {
    echo "Adding item_sort_order column to users table...\n";
    try {
        if ($dbType === 'pgsql') {
            $db->exec("ALTER TABLE users ADD COLUMN item_sort_order VARCHAR(20) DEFAULT 'newest'");
        } else {
            $db->exec("ALTER TABLE users ADD COLUMN item_sort_order TEXT DEFAULT 'newest'");
        }
        echo "✓ Migration complete! Column item_sort_order has been added.\n";
    } catch (PDOException $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Done!\n";