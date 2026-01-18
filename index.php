<?php
// Configure secure session settings before starting session
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_lifetime', '0'); // Session cookie (expires when browser closes)
ini_set('session.gc_maxlifetime', '7200'); // 2 hours

session_start();

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) { // Regenerate every 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

require_once __DIR__ . '/vendor/autoload.php';

use PhpRss\Router;
use PhpRss\Database;

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
