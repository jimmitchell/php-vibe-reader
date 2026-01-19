<?php

namespace PhpRss\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PhpRss\Auth;
use PhpRss\Database;
use PhpRss\Csrf;
use PDO;

/**
 * Base class for integration tests.
 * 
 * Provides common setup and teardown for integration tests, including
 * database initialization, session management, and helper methods for
 * creating test data and making HTTP requests.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var int|null Test user ID */
    protected ?int $testUserId = null;

    /** @var string|null Test username */
    protected ?string $testUsername = null;

    /** @var array Captured output */
    protected array $outputBuffer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize database for testing
        Database::init();
        Database::setup();
        
        // Enable foreign key constraints for SQLite (required for CASCADE deletes)
        $db = Database::getConnection();
        if (Database::getDbType() === 'sqlite') {
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear session and superglobals
        $_SESSION = [];
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        // Set default server variables
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';

        // Create a test user with unique email
        $uniqueId = uniqid();
        $this->testUsername = 'test_user_' . $uniqueId;
        $this->testUserId = $this->createTestUser($this->testUsername, "test_{$uniqueId}@example.com", 'password123');

        // Clear output buffer array (but don't manipulate PHPUnit's buffers)
        $this->outputBuffer = [];
    }

    protected function tearDown(): void
    {
        // Clean up output buffer
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Clean up test data
        $db = Database::getConnection();
        
        // Delete test feeds and their items
        if ($this->testUserId) {
            $stmt = $db->prepare("SELECT id FROM feeds WHERE user_id = ?");
            $stmt->execute([$this->testUserId]);
            $feedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($feedIds)) {
                $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
                $db->prepare("DELETE FROM feed_items WHERE feed_id IN ($placeholders)")->execute($feedIds);
            }
            
            $db->prepare("DELETE FROM feeds WHERE user_id = ?")->execute([$this->testUserId]);
            $db->prepare("DELETE FROM folders WHERE user_id = ?")->execute([$this->testUserId]);
        }
        
        // Delete test users
        $db->exec("DELETE FROM users WHERE username LIKE 'test_%'");
        
        // Clear session
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * Create a test user in the database.
     * 
     * @param string $username Username
     * @param string $email Email address
     * @param string $password Plain text password
     * @return int User ID
     */
    protected function createTestUser(string $username, string $email, string $password): int
    {
        $db = Database::getConnection();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?) RETURNING id");
            $stmt->execute([$username, $email, $passwordHash]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['id'];
        } else {
            // SQLite
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $passwordHash]);
            return (int)$db->lastInsertId();
        }
    }

    /**
     * Login as the test user.
     * 
     * @return bool True if login successful
     */
    protected function loginTestUser(): bool
    {
        return Auth::login($this->testUsername, 'password123');
    }

    /**
     * Set up a mock HTTP request.
     * 
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path
     * @param array $params Query parameters or POST data
     * @param array $headers Additional headers
     * @return void
     */
    protected function setRequest(string $method, string $path, array $params = [], array $headers = []): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path . (!empty($params) && $method === 'GET' ? '?' . http_build_query($params) : '');
        
        if ($method === 'GET') {
            $_GET = $params;
        } elseif ($method === 'POST' || $method === 'PUT') {
            $_POST = $params;
        }

        // Set headers
        foreach ($headers as $key => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }
    }

    /**
     * Get CSRF token for the current session.
     * 
     * @return string CSRF token
     */
    protected function getCsrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * Add CSRF token to request parameters.
     * 
     * @param array $params Request parameters
     * @return array Parameters with CSRF token added
     */
    protected function addCsrfToken(array $params): array
    {
        $params['_token'] = $this->getCsrfToken();
        return $params;
    }

    /**
     * Set JSON input for methods that read from php://input.
     * 
     * @param array $data JSON data to set
     * @return void
     */
    protected function setJsonInput(array $data): void
    {
        // Store JSON data to be read by file_get_contents('php://input')
        // We'll mock file_get_contents or use a stream wrapper
        $json = json_encode($data);
        
        // Use a temporary file approach or mock file_get_contents
        // For now, we'll set it in a way that methods can access it
        // Some methods check $_POST first, so we'll set both
        $_POST = array_merge($_POST, $data);
        
        // Create a temporary stream for php://input
        // Note: In real scenarios, php://input is read-once, so we need to handle this carefully
        // For testing, we'll modify the method to accept data directly or use a helper
    }

    /**
     * Capture output from a callback function.
     * 
     * Captures all output including from Response::error() and Response::json().
     * Uses nested output buffering to avoid interfering with PHPUnit's buffers.
     * 
     * @param callable $callback Function to execute
     * @return string Captured output
     */
    protected function captureOutput(callable $callback): string
    {
        // Use nested output buffer - don't interfere with PHPUnit's buffer
        $initialLevel = ob_get_level();
        ob_start();
        try {
            $callback();
            $output = ob_get_clean();
            
            // Ensure we haven't closed PHPUnit's buffers
            $finalLevel = ob_get_level();
            if ($finalLevel < $initialLevel) {
                // Something closed buffers unexpectedly - restore level
                while (ob_get_level() < $initialLevel) {
                    ob_start();
                }
            }
            
            return $output ?: '';
        } catch (\Exception $e) {
            // Clean up our buffer only
            if (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Get JSON response as array.
     * 
     * @param string $output Output string (JSON)
     * @return array Decoded JSON data
     */
    protected function getJsonResponse(string $output): array
    {
        $decoded = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid JSON response: ' . json_last_error_msg() . "\nOutput: " . $output);
        }
        return $decoded;
    }

    /**
     * Assert JSON response structure.
     * 
     * @param array $response JSON response array
     * @param bool $hasSuccess Whether response should have 'success' key
     * @return void
     */
    protected function assertJsonResponseStructure(array $response, bool $hasSuccess = false): void
    {
        if ($hasSuccess) {
            $this->assertArrayHasKey('success', $response, 'Response should have "success" key');
            $this->assertIsBool($response['success'], '"success" should be boolean');
        }
    }

    /**
     * Create a test feed in the database.
     * 
     * @param int $userId User ID
     * @param string $title Feed title
     * @param string $url Feed URL
     * @return int Feed ID
     */
    protected function createTestFeed(int $userId, string $title = 'Test Feed', string $url = 'https://example.com/feed.xml'): int
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            $stmt = $db->prepare("INSERT INTO feeds (user_id, title, url, feed_type, last_fetched) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
            $stmt->execute([$userId, $title, $url, 'rss']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['id'];
        } else {
            // SQLite
            $stmt = $db->prepare("INSERT INTO feeds (user_id, title, url, feed_type, last_fetched) VALUES (?, ?, ?, ?, datetime('now'))");
            $stmt->execute([$userId, $title, $url, 'rss']);
            return (int)$db->lastInsertId();
        }
    }

    /**
     * Create a test feed item.
     * 
     * @param int $feedId Feed ID
     * @param string $title Item title
     * @param bool $isRead Whether item is read
     * @return int Item ID
     */
    protected function createTestFeedItem(int $feedId, string $title = 'Test Item', bool $isRead = false, ?int $userId = null): int
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            $stmt = $db->prepare("
                INSERT INTO feed_items (feed_id, title, content, published_at, guid) 
                VALUES (?, ?, ?, NOW(), ?) 
                RETURNING id
            ");
            $guid = 'test-guid-' . uniqid();
            $stmt->execute([$feedId, $title, 'Test content', $guid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $itemId = (int)$result['id'];
        } else {
            // SQLite
            $stmt = $db->prepare("
                INSERT INTO feed_items (feed_id, title, content, published_at, guid) 
                VALUES (?, ?, ?, datetime('now'), ?)
            ");
            $guid = 'test-guid-' . uniqid();
            $stmt->execute([$feedId, $title, 'Test content', $guid]);
            $itemId = (int)$db->lastInsertId();
        }
        
        // If item should be marked as read and userId is provided, add to read_items
        if ($isRead && $userId !== null) {
            if ($dbType === 'pgsql') {
                $readStmt = $db->prepare("INSERT INTO read_items (user_id, feed_item_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            } else {
                $readStmt = $db->prepare("INSERT OR IGNORE INTO read_items (user_id, feed_item_id) VALUES (?, ?)");
            }
            $readStmt->execute([$userId, $itemId]);
        }
        
        return $itemId;
    }

    /**
     * Create a test folder.
     * 
     * @param int $userId User ID
     * @param string $name Folder name
     * @return int Folder ID
     */
    protected function createTestFolder(int $userId, string $name = 'Test Folder'): int
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            $stmt = $db->prepare("INSERT INTO folders (user_id, name) VALUES (?, ?) RETURNING id");
            $stmt->execute([$userId, $name]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['id'];
        } else {
            // SQLite
            $stmt = $db->prepare("INSERT INTO folders (user_id, name) VALUES (?, ?)");
            $stmt->execute([$userId, $name]);
            return (int)$db->lastInsertId();
        }
    }
}
