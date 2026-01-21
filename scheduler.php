<?php

/**
 * Feed refresh scheduler script.
 * 
 * Queues feed refresh jobs for all feeds that need updating.
 * Should be run periodically via cron (e.g., every 15-30 minutes).
 * 
 * Usage:
 *   php scheduler.php [--interval=minutes]
 * 
 * Example cron job (every 15 minutes):
 *   0,15,30,45 * * * * cd /path/to/vibereader && php scheduler.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpRss\Config;
use PhpRss\Database;
use PhpRss\Queue\JobQueue;

// Initialize
Config::reset();
Database::init();
Database::setup();

// Parse command line arguments
$refreshInterval = 15; // Default: refresh feeds every 15 minutes

foreach ($argv as $arg) {
    if (strpos($arg, '--interval=') === 0) {
        $refreshInterval = (int)substr($arg, 11);
    }
}

// Check if jobs are enabled
if (! Config::get('jobs.enabled', false)) {
    echo "Background jobs are disabled. Set JOBS_ENABLED=1 to enable scheduled refreshes.\n";
    exit(1);
}

$db = Database::getConnection();
$dbType = Database::getDbType();

// Get all feeds that need refreshing
// Refresh feeds that haven't been fetched in the last N minutes
if ($dbType === 'pgsql') {
    $stmt = $db->query("
        SELECT id, url, last_fetched
        FROM feeds
        WHERE last_fetched IS NULL 
           OR last_fetched < CURRENT_TIMESTAMP - INTERVAL '{$refreshInterval} minutes'
        ORDER BY last_fetched ASC NULLS FIRST
    ");
} else {
    // SQLite
    $stmt = $db->query("
        SELECT id, url, last_fetched
        FROM feeds
        WHERE last_fetched IS NULL 
           OR last_fetched < datetime('now', '-{$refreshInterval} minutes')
        ORDER BY last_fetched ASC
    ");
}

$feeds = $stmt->fetchAll(\PDO::FETCH_ASSOC);
$queued = 0;
$skipped = 0;

foreach ($feeds as $feed) {
    // Check if there's already a pending job for this feed
    $existingJob = $db->prepare("
        SELECT id FROM jobs 
        WHERE type = ? 
        AND payload LIKE ? 
        AND status = ?
        LIMIT 1
    ");
    
    $payloadPattern = '%"feed_id":' . $feed['id'] . '%';
    $existingJob->execute([
        JobQueue::TYPE_FETCH_FEED,
        $payloadPattern,
        JobQueue::STATUS_PENDING
    ]);
    
    if ($existingJob->fetch()) {
        // Job already queued for this feed, skip
        $skipped++;
        continue;
    }
    
    // Queue the refresh job
    try {
        JobQueue::push(
            JobQueue::TYPE_FETCH_FEED,
            ['feed_id' => (int)$feed['id']]
        );
        $queued++;
    } catch (\Exception $e) {
        echo "Error queueing feed {$feed['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Scheduled refresh: {$queued} feeds queued, {$skipped} already queued, " . count($feeds) . " total need refreshing\n";
