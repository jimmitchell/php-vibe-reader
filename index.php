<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpRss\Config;
use PhpRss\Router;
use PhpRss\Database;

// Configure secure session settings before starting session
$sessionConfig = Config::get('session', []);
ini_set('session.cookie_httponly', $sessionConfig['cookie_httponly'] ? '1' : '0');
ini_set('session.cookie_secure', $sessionConfig['cookie_secure'] ? '1' : '0');
ini_set('session.cookie_samesite', $sessionConfig['cookie_samesite'] ?? 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_lifetime', '0'); // Session cookie (expires when browser closes)
ini_set('session.gc_maxlifetime', (string)($sessionConfig['lifetime'] ?? 7200));

session_start();

// Regenerate session ID periodically to prevent session fixation
$regenerateInterval = Config::get('session.regenerate_interval', 1800);
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > $regenerateInterval) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Initialize database connection
Database::init();

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Content Security Policy (adjust as needed for your application)
$csp = "default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self';";
header("Content-Security-Policy: $csp");

// Route the request
$router = new Router();
$router->dispatch();
