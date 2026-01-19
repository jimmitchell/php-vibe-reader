<?php

/**
 * Background job worker script.
 * 
 * Processes jobs from the queue. Can be run:
 * - Manually: php worker.php
 * - Via cron: Every 5 minutes: php /path/to/worker.php
 * - As a daemon: php worker.php --daemon
 * 
 * Usage:
 *   php worker.php [--daemon] [--max-jobs=N] [--sleep=N]
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpRss\Config;
use PhpRss\Database;
use PhpRss\Queue\Worker;

// Initialize
Config::reset();
Database::init();
Database::setup();

// Parse command line arguments
$daemon = in_array('--daemon', $argv);
$maxJobs = 0;
$sleep = Config::get('jobs.worker_sleep', 5);

foreach ($argv as $arg) {
    if (strpos($arg, '--max-jobs=') === 0) {
        $maxJobs = (int)substr($arg, 11);
    } elseif (strpos($arg, '--sleep=') === 0) {
        $sleep = (int)substr($arg, 8);
    }
}

if ($daemon) {
    // Run continuously
    echo "Worker started in daemon mode. Press Ctrl+C to stop.\n";
    while (true) {
        Worker::run(0, $sleep);
        // Small delay to prevent tight loop
        usleep(100000); // 0.1 seconds
    }
} else {
    // Process jobs once
    $processed = 0;
    while (Worker::processNext()) {
        $processed++;
        if ($maxJobs > 0 && $processed >= $maxJobs) {
            break;
        }
    }
    
    if ($processed > 0) {
        echo "Processed {$processed} job(s).\n";
    } else {
        echo "No jobs to process.\n";
    }
}
