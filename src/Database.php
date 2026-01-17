<?php

namespace PhpRss;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;
    private static string $dbType = 'sqlite';
    private static string $dbPath = __DIR__ . '/../data/rss_reader.db';

    public static function init(): void
    {
        if (self::$connection === null) {
            self::connect();
        }
    }

    private static function connect(): void
    {
        try {
            if (self::$dbType === 'sqlite') {
                // Ensure data directory exists
                $dataDir = dirname(self::$dbPath);
                if (!is_dir($dataDir)) {
                    mkdir($dataDir, 0755, true);
                }

                self::$connection = new PDO('sqlite:' . self::$dbPath);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } else {
                // Future: MySQL/PostgreSQL support
                throw new \Exception('Database type not yet implemented');
            }
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::init();
        }
        return self::$connection;
    }

    public static function setup(): void
    {
        $db = self::getConnection();
        
        // Users table
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            hide_read_items INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Feeds table
        $db->exec("CREATE TABLE IF NOT EXISTS feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            url TEXT NOT NULL,
            feed_type TEXT NOT NULL,
            description TEXT,
            last_fetched DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, url)
        )");

        // Feed items table
        $db->exec("CREATE TABLE IF NOT EXISTS feed_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            link TEXT,
            content TEXT,
            summary TEXT,
            author TEXT,
            published_at DATETIME,
            guid TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE,
            UNIQUE(feed_id, guid)
        )");

        // Read items tracking
        $db->exec("CREATE TABLE IF NOT EXISTS read_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            feed_item_id INTEGER NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (feed_item_id) REFERENCES feed_items(id) ON DELETE CASCADE,
            UNIQUE(user_id, feed_item_id)
        )");

        // Create indexes for performance
        $db->exec("CREATE INDEX IF NOT EXISTS idx_feeds_user_id ON feeds(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_feed_items_feed_id ON feed_items(feed_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_read_items_user_id ON read_items(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_read_items_feed_item_id ON read_items(feed_item_id)");

        // Add hide_read_items column to users table if it doesn't exist
        try {
            $db->exec("ALTER TABLE users ADD COLUMN hide_read_items INTEGER DEFAULT 1");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        // Add dark_mode column to users table if it doesn't exist
        try {
            $db->exec("ALTER TABLE users ADD COLUMN dark_mode INTEGER DEFAULT 0");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        // Add sort_order column to feeds table if it doesn't exist
        try {
            $db->exec("ALTER TABLE feeds ADD COLUMN sort_order INTEGER DEFAULT 0");
            // Backfill existing feeds with a stable order (by id)
            $db->exec("UPDATE feeds SET sort_order = id WHERE sort_order = 0");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        // Add timezone and default_theme_mode columns to users table if they don't exist
        try {
            $db->exec("ALTER TABLE users ADD COLUMN timezone TEXT DEFAULT 'UTC'");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE users ADD COLUMN default_theme_mode TEXT DEFAULT 'system'");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }
    }
}
