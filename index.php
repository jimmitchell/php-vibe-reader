<?php
session_start();

require_once __DIR__ . '/vendor/autoload.php';

use PhpRss\Router;
use PhpRss\Database;

// Initialize database connection
Database::init();

// Route the request
$router = new Router();
$router->dispatch();
