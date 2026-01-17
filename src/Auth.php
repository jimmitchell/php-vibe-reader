<?php

namespace PhpRss;

use PDO;

class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, email, COALESCE(hide_read_items, 1) as hide_read_items, COALESCE(dark_mode, 0) as dark_mode, COALESCE(timezone, 'UTC') as timezone, COALESCE(default_theme_mode, 'system') as default_theme_mode, COALESCE(font_family, 'system') as font_family FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['hide_read_items'] = (bool)$user['hide_read_items'];
            $_SESSION['dark_mode'] = (bool)($user['dark_mode'] ?? 0);
            $_SESSION['timezone'] = $user['timezone'] ?? 'UTC';
            $_SESSION['default_theme_mode'] = $user['default_theme_mode'] ?? 'system';
            $_SESSION['font_family'] = $user['font_family'] ?? 'system';
        }
        
        return $user ?: null;
    }

    public static function login(string $username, string $password): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            // Load user preferences
            $stmt = $db->prepare("SELECT COALESCE(hide_read_items, 1) as hide_read_items, COALESCE(dark_mode, 0) as dark_mode, COALESCE(timezone, 'UTC') as timezone, COALESCE(default_theme_mode, 'system') as default_theme_mode, COALESCE(font_family, 'system') as font_family FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $pref = $stmt->fetch();
            $_SESSION['hide_read_items'] = (bool)($pref['hide_read_items'] ?? 1);
            $_SESSION['dark_mode'] = (bool)($pref['dark_mode'] ?? 0);
            $_SESSION['timezone'] = $pref['timezone'] ?? 'UTC';
            $_SESSION['default_theme_mode'] = $pref['default_theme_mode'] ?? 'system';
            $_SESSION['font_family'] = $pref['font_family'] ?? 'system';
            return true;
        }

        return false;
    }

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

    public static function logout(): void
    {
        session_destroy();
        $_SESSION = [];
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /');
            exit;
        }
    }
}
