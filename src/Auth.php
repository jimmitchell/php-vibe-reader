<?php

namespace PhpRss;

/**
 * Authentication and user session management class.
 *
 * Handles user authentication, registration, login/logout, and provides
 * access to the current user's information and preferences.
 */
class Auth
{
    /**
     * Check if a user is currently authenticated.
     *
     * @return bool True if a user is logged in (has a user_id in session), false otherwise
     */
    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * Get the currently authenticated user's information.
     *
     * Fetches user data from the database and loads user preferences into
     * the session for quick access. Returns null if no user is authenticated.
     *
     * @return array|null User data array with id, username, email, and preferences,
     *                    or null if not authenticated
     */
    public static function user(): ?array
    {
        if (! self::check()) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, email, COALESCE(hide_read_items, 1) as hide_read_items, COALESCE(dark_mode, 0) as dark_mode, COALESCE(timezone, 'UTC') as timezone, COALESCE(default_theme_mode, 'system') as default_theme_mode, COALESCE(font_family, 'system') as font_family, COALESCE(hide_feeds_with_no_unread, 0) as hide_feeds_with_no_unread, COALESCE(item_sort_order, 'newest') as item_sort_order FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['hide_read_items'] = (bool)$user['hide_read_items'];
            $_SESSION['dark_mode'] = (bool)($user['dark_mode'] ?? 0);
            $_SESSION['timezone'] = $user['timezone'] ?? 'UTC';
            $_SESSION['default_theme_mode'] = $user['default_theme_mode'] ?? 'system';
            $_SESSION['font_family'] = $user['font_family'] ?? 'system';
            $_SESSION['hide_feeds_with_no_unread'] = (bool)($user['hide_feeds_with_no_unread'] ?? 0);
            $_SESSION['item_sort_order'] = $user['item_sort_order'] ?? 'newest';
        }

        return $user ?: null;
    }

    /**
     * Authenticate a user with username/email and password.
     *
     * Checks credentials against the database and, if valid, creates a session
     * and loads user preferences into the session.
     *
     * @param string $username Username or email address
     * @param string $password Plain text password (will be verified using password_verify)
     * @return bool True if login successful, false if credentials are invalid
     */
    public static function login(string $username, string $password): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate session ID on login to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            // Load user preferences
            $stmt = $db->prepare("SELECT COALESCE(hide_read_items, 1) as hide_read_items, COALESCE(dark_mode, 0) as dark_mode, COALESCE(timezone, 'UTC') as timezone, COALESCE(default_theme_mode, 'system') as default_theme_mode, COALESCE(font_family, 'system') as font_family, COALESCE(hide_feeds_with_no_unread, 0) as hide_feeds_with_no_unread, COALESCE(item_sort_order, 'newest') as item_sort_order FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $pref = $stmt->fetch();
            $_SESSION['hide_read_items'] = (bool)($pref['hide_read_items'] ?? 1);
            $_SESSION['dark_mode'] = (bool)($pref['dark_mode'] ?? 0);
            $_SESSION['timezone'] = $pref['timezone'] ?? 'UTC';
            $_SESSION['default_theme_mode'] = $pref['default_theme_mode'] ?? 'system';
            $_SESSION['font_family'] = $pref['font_family'] ?? 'system';
            $_SESSION['hide_feeds_with_no_unread'] = (bool)($pref['hide_feeds_with_no_unread'] ?? 0);
            $_SESSION['item_sort_order'] = $pref['item_sort_order'] ?? 'newest';

            return true;
        }

        return false;
    }

    /**
     * Register a new user account.
     *
     * Creates a new user in the database with a hashed password. Validates
     * that the username and email are unique before creating the account.
     *
     * @param string $username Desired username (must be unique)
     * @param string $email User's email address (must be unique)
     * @param string $password Plain text password (will be hashed using PASSWORD_DEFAULT)
     * @return bool True if registration successful, false if username/email already exists
     */
    public static function register(string $username, string $email, string $password): bool
    {
        $db = Database::getConnection();

        // Check if username or email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");

        return $stmt->execute([$username, $email, $passwordHash]);
    }

    /**
     * Log out the current user.
     *
     * Destroys the session and clears all session data.
     *
     * @return void
     */
    public static function logout(): void
    {
        session_destroy();
        $_SESSION = [];
    }

    /**
     * Require that a user is authenticated.
     *
     * If no user is authenticated, redirects to the home page (login/register)
     * and terminates script execution. Use this to protect routes that require
     * authentication.
     *
     * @return void
     * @throws void Exits script execution if not authenticated
     */
    public static function requireAuth(): void
    {
        if (! self::check()) {
            header('Location: /');
            exit;
        }
    }
}
