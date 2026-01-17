<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpRss\Database;

echo "Setting up VibeReader...\n";

// Initialize database
Database::init();

// Create tables
Database::setup();

echo "Database setup complete!\n";
echo "You can now access the application at http://localhost\n";
