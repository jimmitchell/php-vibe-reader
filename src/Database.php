<?php

namespace PhpRss;

use PDO;
use PDOException;

/**
 * Database connection and schema management class.
 *
 * Handles database connections for both SQLite and PostgreSQL, manages
 * the database schema, and provides migration capabilities for adding
 * new columns and indexes as the application evolves.
 */
class Database
{
    /** @var PDO|null Singleton database connection instance */
    private static ?PDO $connection = null;

    /** @var string Database type: 'sqlite' or 'pgsql' */
    private static string $dbType;

    /** @var string Path to SQLite database file (used when DB_TYPE is 'sqlite') */
    private static string $dbPath = __DIR__ . '/../data/rss_reader.db';

    /** @var array Database configuration array (currently unused, reserved for future use) */
    /** @phpstan-ignore-next-line */
    private static array $dbConfig = [];

    /**
     * Initialize the database connection if it hasn't been established yet.
     *
     * This method ensures the connection is lazy-loaded - it will only
     * be created when first accessed.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$connection === null) {
            self::connect();
        }
    }

    /**
     * Establish a database connection based on environment variables.
     *
     * Reads DB_TYPE, DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD
     * from environment variables. Falls back to SQLite if DB_TYPE is not
     * set or is not 'pgsql'/'postgresql'.
     *
     * @return void
     * @throws PDOException If connection fails
     */
    private static function connect(): void
    {
        try {
            // Get database configuration from Config class
            self::$dbType = Config::get('database.type', 'sqlite');

            if (self::$dbType === 'pgsql' || self::$dbType === 'postgresql') {
                // PostgreSQL connection
                $host = Config::get('database.host', 'localhost');
                $port = Config::get('database.port', '5432');
                $dbname = Config::get('database.name', 'vibereader');
                $user = Config::get('database.user', 'vibereader');
                $password = Config::get('database.password', 'vibereader');

                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
                self::$connection = new PDO($dsn, $user, $password);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$dbType = 'pgsql';
            } else {
                // SQLite connection (fallback)
                $dataDir = dirname(self::$dbPath);
                if (! is_dir($dataDir)) {
                    mkdir($dataDir, 0755, true);
                }

                self::$connection = new PDO('sqlite:' . self::$dbPath);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$dbType = 'sqlite';
            }
        } catch (PDOException $e) {
            // Don't expose database connection details in production
            \PhpRss\Logger::exception($e, ['context' => 'database_connection']);
            if (Config::isDevelopment()) {
                die('Database connection failed: ' . $e->getMessage());
            } else {
                die('Database connection failed. Please contact the administrator.');
            }
        }
    }

    /**
     * Get the database connection instance.
     *
     * If no connection exists, this will automatically initialize one.
     * Returns a PDO instance configured for exceptions and associative array fetching.
     *
     * @return PDO The database connection instance
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::init();
        }

        return self::$connection;
    }

    /**
     * Get the database type being used.
     *
     * Returns either 'sqlite' or 'pgsql' based on the current connection.
     *
     * @return string The database type: 'sqlite' or 'pgsql'
     */
    public static function getDbType(): string
    {
        if (self::$connection === null) {
            self::init();
        }

        return self::$dbType;
    }

    /**
     * Check if a column exists in a database table.
     *
     * Uses database-specific queries to check column existence:
     * - PostgreSQL: queries information_schema.columns
     * - SQLite: queries PRAGMA table_info
     *
     * @param PDO $db The database connection
     * @param string $table The table name to check
     * @param string $column The column name to check
     * @return bool True if the column exists, false otherwise
     */
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

    /**
     * Set up the database schema and run migrations.
     *
     * Creates all necessary tables (users, folders, feeds, feed_items, read_items)
     * if they don't exist, with syntax appropriate for the current database type.
     * Also creates indexes for performance and handles migrations by checking for
     * and adding columns that may not exist in older database schemas.
     *
     * This method is idempotent - it can be safely called multiple times as it
     * uses CREATE TABLE IF NOT EXISTS and checks for column existence before
     * altering tables.
     *
     * @return void
     */
    public static function setup(): void
    {
        $db = self::getConnection();

        // Initialize job queue table
        \PhpRss\Queue\JobQueue::initialize();

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
                hide_feeds_with_no_unread INTEGER DEFAULT 0,
                item_sort_order VARCHAR(20) DEFAULT 'newest',
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
        if (! self::columnExists($db, 'users', 'hide_read_items')) {
            $db->exec("ALTER TABLE users ADD COLUMN hide_read_items INTEGER DEFAULT 1");
        }

        if (! self::columnExists($db, 'users', 'dark_mode')) {
            $db->exec("ALTER TABLE users ADD COLUMN dark_mode INTEGER DEFAULT 0");
        }

        if (! self::columnExists($db, 'users', 'timezone')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(255) DEFAULT 'UTC'" : "TEXT DEFAULT 'UTC'";
            $db->exec("ALTER TABLE users ADD COLUMN timezone {$type}");
        }

        if (! self::columnExists($db, 'users', 'default_theme_mode')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(50) DEFAULT 'system'" : "TEXT DEFAULT 'system'";
            $db->exec("ALTER TABLE users ADD COLUMN default_theme_mode {$type}");
        }

        if (! self::columnExists($db, 'users', 'font_family')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(100) DEFAULT 'system'" : "TEXT DEFAULT 'system'";
            $db->exec("ALTER TABLE users ADD COLUMN font_family {$type}");
        }

        // Add hide_feeds_with_no_unread column if it doesn't exist
        if (! self::columnExists($db, 'users', 'hide_feeds_with_no_unread')) {
            $db->exec("ALTER TABLE users ADD COLUMN hide_feeds_with_no_unread INTEGER DEFAULT 0");
        }

        // Add item_sort_order column if it doesn't exist
        if (! self::columnExists($db, 'users', 'item_sort_order')) {
            $type = self::$dbType === 'pgsql' ? "VARCHAR(20) DEFAULT 'newest'" : "TEXT DEFAULT 'newest'";
            $db->exec("ALTER TABLE users ADD COLUMN item_sort_order {$type}");
        }

        if (! self::columnExists($db, 'feeds', 'sort_order')) {
            $db->exec("ALTER TABLE feeds ADD COLUMN sort_order INTEGER DEFAULT 0");
            // Backfill existing feeds with a stable order (by id)
            $db->exec("UPDATE feeds SET sort_order = id WHERE sort_order = 0");
        }

        // Add folder_id column to feeds table if it doesn't exist
        if (! self::columnExists($db, 'feeds', 'folder_id')) {
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
