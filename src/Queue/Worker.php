<?php

namespace PhpRss\Queue;

use PhpRss\FeedFetcher;
use PhpRss\Logger;
use PhpRss\Services\FeedService;

/**
 * Worker for processing jobs from the queue.
 *
 * Processes jobs in the background, handling feed fetching and other
 * asynchronous tasks. Can run continuously or process a single job.
 */
class Worker
{
    /**
     * Process a single job from the queue.
     *
     * @return bool True if a job was processed, false if queue is empty
     */
    public static function processNext(): bool
    {
        $job = JobQueue::pop();

        if (! $job) {
            return false;
        }

        try {
            self::handleJob($job);
            JobQueue::complete($job['id']);

            return true;
        } catch (\Exception $e) {
            Logger::exception($e, [
                'job_id' => $job['id'],
                'job_type' => $job['type'],
                'context' => 'job_processing',
            ]);

            $errorMessage = $e->getMessage();
            if (strlen($errorMessage) > 500) {
                $errorMessage = substr($errorMessage, 0, 500) . '...';
            }

            JobQueue::fail($job['id'], $errorMessage);

            // Retry if attempts haven't been exceeded
            if ($job['attempts'] < $job['max_attempts']) {
                JobQueue::retry($job['id']);
            }

            return true;
        }
    }

    /**
     * Process jobs continuously until stopped or no jobs remain.
     *
     * @param int $maxJobs Maximum number of jobs to process (0 = unlimited)
     * @param int $sleepSeconds Seconds to sleep when no jobs are available
     * @return void
     */
    public static function run(int $maxJobs = 0, int $sleepSeconds = 5): void
    {
        $processed = 0;

        while (true) {
            $processedJob = self::processNext();

            if ($processedJob) {
                $processed++;
                if ($maxJobs > 0 && $processed >= $maxJobs) {
                    break;
                }
            } else {
                // No jobs available, sleep
                if ($sleepSeconds > 0) {
                    sleep($sleepSeconds);
                } else {
                    // If sleep is 0, exit when no jobs are available
                    break;
                }
            }
        }
    }

    /**
     * Handle a specific job based on its type.
     *
     * @param array $job Job data
     * @return void
     * @throws \Exception If job type is unknown or processing fails
     */
    private static function handleJob(array $job): void
    {
        switch ($job['type']) {
            case JobQueue::TYPE_FETCH_FEED:
                self::handleFetchFeed($job['payload']);

                break;

            case JobQueue::TYPE_CLEANUP_ITEMS:
                self::handleCleanupItems($job['payload']);

                break;

            default:
                throw new \Exception("Unknown job type: {$job['type']}");
        }
    }

    /**
     * Handle a feed fetch job.
     *
     * @param array $payload Job payload containing 'feed_id'
     * @return void
     * @throws \Exception If feed fetch fails
     */
    private static function handleFetchFeed(array $payload): void
    {
        if (! isset($payload['feed_id'])) {
            throw new \Exception("Missing feed_id in fetch_feed job payload");
        }

        $feedId = (int)$payload['feed_id'];
        $success = FeedFetcher::updateFeed($feedId);

        if (! $success) {
            throw new \Exception("Failed to update feed {$feedId}");
        }

        // Invalidate cache after successful update
        FeedService::invalidateFeedCache($feedId);

        // Get user_id from feed to invalidate user cache
        $db = \PhpRss\Database::getConnection();
        $stmt = $db->prepare("SELECT user_id FROM feeds WHERE id = ?");
        $stmt->execute([$feedId]);
        $feed = $stmt->fetch();
        if ($feed) {
            FeedService::invalidateUserCache($feed['user_id']);
        }
    }

    /**
     * Handle a feed items cleanup job.
     *
     * @param array $payload Job payload (may contain 'feed_id' for specific feed, or empty for all)
     * @return void
     * @throws \Exception If cleanup fails
     */
    private static function handleCleanupItems(array $payload): void
    {
        $feedId = isset($payload['feed_id']) ? (int)$payload['feed_id'] : null;
        $retentionDays = $payload['retention_days'] ?? null;
        $retentionCount = $payload['retention_count'] ?? null;

        \PhpRss\Services\FeedCleanupService::cleanupItems($feedId, $retentionDays, $retentionCount);
    }
}
