<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Auth;
use PhpRss\Database;
use PDO;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // Initialize database for testing
        Database::init();
        Database::setup();
        
        // Start session for Auth tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Clear session
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Clean up: remove test users
        $db = Database::getConnection();
        $db->exec("DELETE FROM users WHERE username LIKE 'test_%'");
        
        // Clear session
        $_SESSION = [];
    }

    public function testCheckReturnsFalseWhenNotLoggedIn(): void
    {
        $this->assertFalse(Auth::check());
    }

    public function testCheckReturnsTrueWhenLoggedIn(): void
    {
        // Create a test user and login
        $this->createTestUser('test_user', 'test@example.com', 'password123');
        $this->assertTrue(Auth::login('test_user', 'password123'));
        $this->assertTrue(Auth::check());
    }

    public function testUserReturnsNullWhenNotAuthenticated(): void
    {
        $user = Auth::user();
        $this->assertNull($user);
    }

    public function testUserReturnsUserDataWhenAuthenticated(): void
    {
        $username = 'test_user';
        $email = 'test@example.com';
        $this->createTestUser($username, $email, 'password123');
        
        Auth::login($username, 'password123');
        $user = Auth::user();
        
        $this->assertNotNull($user);
        $this->assertEquals($username, $user['username']);
        $this->assertEquals($email, $user['email']);
        $this->assertArrayHasKey('id', $user);
    }

    public function testLoginWithValidCredentials(): void
    {
        $username = 'test_user';
        $this->createTestUser($username, 'test@example.com', 'password123');
        
        $result = Auth::login($username, 'password123');
        $this->assertTrue($result);
        $this->assertTrue(Auth::check());
        $this->assertEquals($username, $_SESSION['username'] ?? null);
    }

    public function testLoginWithInvalidPassword(): void
    {
        $username = 'test_user';
        $this->createTestUser($username, 'test@example.com', 'password123');
        
        $result = Auth::login($username, 'wrong_password');
        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }

    public function testLoginWithInvalidUsername(): void
    {
        $result = Auth::login('nonexistent_user', 'password123');
        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }

    public function testLoginWithEmail(): void
    {
        $email = 'test@example.com';
        $this->createTestUser('test_user', $email, 'password123');
        
        $result = Auth::login($email, 'password123');
        $this->assertTrue($result);
        $this->assertTrue(Auth::check());
    }

    public function testRegisterWithNewUser(): void
    {
        $result = Auth::register('test_new_user', 'newuser@example.com', 'password123');
        $this->assertTrue($result);
        
        // Verify user was created
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute(['test_new_user']);
        $user = $stmt->fetch();
        $this->assertNotFalse($user);
        $this->assertEquals('newuser@example.com', $user['email']);
    }

    public function testRegisterWithDuplicateUsername(): void
    {
        $username = 'test_duplicate';
        $this->createTestUser($username, 'first@example.com', 'password123');
        
        // Try to register with same username
        $result = Auth::register($username, 'second@example.com', 'password456');
        $this->assertFalse($result);
    }

    public function testRegisterWithDuplicateEmail(): void
    {
        $email = 'duplicate@example.com';
        $this->createTestUser('test_first', $email, 'password123');
        
        // Try to register with same email
        $result = Auth::register('test_second', $email, 'password456');
        $this->assertFalse($result);
    }

    public function testLogout(): void
    {
        // Login first
        $this->createTestUser('test_user', 'test@example.com', 'password123');
        Auth::login('test_user', 'password123');
        $this->assertTrue(Auth::check());
        
        // Logout
        Auth::logout();
        $this->assertFalse(Auth::check());
    }

    public function testUserLoadsPreferencesIntoSession(): void
    {
        $username = 'test_user';
        $this->createTestUser($username, 'test@example.com', 'password123');
        
        Auth::login($username, 'password123');
        Auth::user(); // Load preferences
        
        // Check that preferences are in session
        $this->assertArrayHasKey('hide_read_items', $_SESSION);
        $this->assertArrayHasKey('dark_mode', $_SESSION);
        $this->assertArrayHasKey('timezone', $_SESSION);
    }

    /**
     * Helper method to create a test user in the database.
     */
    private function createTestUser(string $username, string $email, string $password): void
    {
        $db = Database::getConnection();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $passwordHash]);
    }
}
