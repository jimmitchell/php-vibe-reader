<?php

namespace PhpRss;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;
    private static string $dbType;
    private static string $dbPath = __DIR__ . '/../data/rss_reader.db';
    private static array $dbConfig = [];

    public static function init(): void
    {
        if (self::$connection === null) {
            self::connect();
        }
    }

    private static function connect(): void
    {
        try {
            // Determine database type from environment variable
            // Use getenv() as $_ENV may not be populated in CLI mode
            self::$dbType = getenv('DB_TYPE') ?: 'sqlite';
            
            if (self::$dbType === 'pgsql' || self::$dbType === 'postgresql') {
                // PostgreSQL connection
                $host = getenv('DB_HOST') ?: 'localhost';
                $port = getenv('DB_PORT') ?: '5432';
                $dbname = getenv('DB_NAME') ?: 'vibereader';
                $user = getenv('DB_USER') ?: 'vibereader';
                $password = getenv('DB_PASSWORD') ?: 'vibereader';

                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
                self::$connection = new PDO($dsn, $user, $password);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$dbType = 'pgsql';
            } else {
                // SQLite connection (fallback)
                $dataDir = dirname(self::$dbPath);
                if (!is_dir($dataDir)) {
                    mkdir($dataDir, 0755, true);
                }

                self::$connection = new PDO('sqlite:' . self::$dbPath);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$dbType = 'sqlite';
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

    public static function getDbType(): string
    {
        if (self::$connection === null) {
            self::init();
        }
        return self::$dbType;
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        if (self::$dbType === 'pgsql') {
            $stmt = $db->prepare("
                SELECT 1 
                FROM information_schema.columns 
                WHERE table_name = :table AND column_name = :column
            ");
            $stmt->execute([':table' => $table, ':column' => $column]);
            return $stmt->fetchColumn() !== false;
        } else {
            // SQLite
            $stmt = $db->prepare("PRAGMA table_info({$table})");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $col) {
                if ($col['name'] === $column) {
                    return true;
                }
            }
            return false;
        }
    }

    public static function setup(): void
    {
        $db = self::getConnection();
        
        // SQL syntax differs between SQLite and PostgreSQL
        if (self::$dbType === 'pgsql') {
            // PostgreSQL schema
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                hide_read_items INTEGER DEFAULT 1,
                dark_mode INTEGER DEFAULT 0,
                timezone VARCHAR(255) DEFAULT 'UTC',
                default_theme_mode VARCHAR(50) DEFAULT 'system',
                font_family VARCHAR(100) DEFAULT 'system',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS folders (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                sort_order INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id, name)
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS feeds (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                folder_id INTEGER,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                feed_type VARCHAR(50) NOT NULL,
                description TEXT,
                last_fetched TIMESTAMP,
                sort_order INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
                UNIQUE(user_id, url)
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS feed_items (
                id SERIAL PRIMARY KEY,
                feed_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                link TEXT,
                content TEXT,
                summary TEXT,
                author TEXT,
                published_at TIMESTAMP,
                guid TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE,
                UNIQUE(feed_id, guid)
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS read_items (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                feed_item_id INTEGER NOT NULL,
                read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (feed_item_id) REFERENCES feed_items(id) ON DELETE CASCADE,
                UNIQUE(user_id, feed_item_id)
            )");
        } else {
            // SQLite schema (original)
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                hide_read_items INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id, name)
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS feeds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                folder_id INTEGER,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                feed_type TEXT NOT NULL,
                description TEXT,
                last_fetched DATETIME,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
                UNIQUE(user_id, url)
            )");

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

            $db->exec("CREATE TABLE IF NOT EXISTS read_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                feed_item_id INTEGER NOT NULL,
                read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (feed_item_id) REFERENCES feed_items(id) ON DELETE CASCADE,
                UNIQUE(user_id, feed_item_id)
            )");
        }

        // Create indexes for performance (basic tables)
        $db->exec("CREATE INDEX IF NOT EXISTS idx_feeds_user_id ON feeds(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_feed_items_feed_id ON feed_items(feed_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_read_items_user_id ON read_items(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_read_items_feed_item_id ON read_items(feed_item_id)");

        // Add columns that might not exist (for migrations)
        if (!self::columnExists($db, 'users', 'hide_read_items')) {
            $db->exec("ALTER TABLE users ADD COLUMN hide_read_items INTEGER DEFAULT 1");
        }

        if (!self::columnExists($db, 'users', 'dark_mode')) {
            $db->exec("ALTER TABLE users ADD COLUMN dark_mode INTEGER DEFAULT 0");
        }

        if (!self::columnExists($db, 'users', 'timezone')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(255) DEFAULT 'UTC'" : "TEXT DEFAULT 'UTC'";
            $db->exec("ALTER TABLE users ADD COLUMN timezone {$type}");
        }

        if (!self::columnExists($db, 'users', 'default_theme_mode')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(50) DEFAULT 'system'" : "TEXT DEFAULT 'system'";
            $db->exec("ALTER TABLE users ADD COLUMN default_theme_mode {$type}");
        }

        if (!self::columnExists($db, 'users', 'font_family')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(100) DEFAULT 'system'" : "TEXT DEFAULT 'system'";
            $db->exec("ALTER TABLE users ADD COLUMN font_family {$type}");
        }

        if (!self::columnExists($db, 'feeds', 'sort_order')) {
            $db->exec("ALTER TABLE feeds ADD COLUMN sort_order INTEGER DEFAULT 0");
            // Backfill existing feeds with a stable order (by id)
            $db->exec("UPDATE feeds SET sort_order = id WHERE sort_order = 0");
        }

        // Add folder_id column to feeds table if it doesn't exist
        if (!self::columnExists($db, 'feeds', 'folder_id')) {
            $db->exec("ALTER TABLE feeds ADD COLUMN folder_id INTEGER");
        }
        
        // Create indexes for folders (after tables/columns exist)
        // Check if folders table exists by trying to create index (folder table should exist from CREATE TABLE)
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_folders_user_id ON folders(user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_feeds_folder_id ON feeds(folder_id)");
        } catch (PDOException $e) {
            // Folders table might not exist yet (for very old databases), ignore
        }

        // Create index for folders
        $db->exec("CREATE INDEX IF NOT EXISTS idx_folders_user_id ON folders(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_feeds_folder_id ON feeds(folder_id)");
    }
}
