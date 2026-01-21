<?php

namespace PhpRss\Queue;

use PDO;
use PDOException;
use PhpRss\Database;
use PhpRss\Logger;

/**
 * Job queue for background processing.
 *
 * Provides a simple database-backed job queue system for processing
 * tasks asynchronously, such as feed fetching and cleanup operations.
 */
class JobQueue
{
    /**
     * Job status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Job type constants
     */
    public const TYPE_FETCH_FEED = 'fetch_feed';
    public const TYPE_CLEANUP_ITEMS = 'cleanup_items';

    /**
     * Initialize the jobs table if it doesn't exist.
     *
     * @return void
     */
    public static function initialize(): void
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();

        if ($dbType === 'pgsql') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS jobs (
                    id SERIAL PRIMARY KEY,
                    type VARCHAR(50) NOT NULL,
                    payload TEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    max_attempts INT NOT NULL DEFAULT 3,
                    error_message TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    started_at TIMESTAMP,
                    completed_at TIMESTAMP
                )
            ");

            // Create index for pending jobs
            try {
                $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_type ON jobs(type)");
            } catch (PDOException $e) {
                // Index might already exist
            }
        } else {
            // SQLite
            $db->exec("
                CREATE TABLE IF NOT EXISTS jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type VARCHAR(50) NOT NULL,
                    payload TEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    max_attempts INT NOT NULL DEFAULT 3,
                    error_message TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    started_at TIMESTAMP,
                    completed_at TIMESTAMP
                )
            ");

            // Create indexes
            try {
                $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_type ON jobs(type)");
            } catch (PDOException $e) {
                // Index might already exist
            }
        }
    }

    /**
     * Add a job to the queue.
     *
     * @param string $type Job type (e.g., 'fetch_feed', 'cleanup_items')
     * @param array $payload Job data (will be JSON encoded)
     * @return int Job ID
     */
    public static function push(string $type, array $payload): int
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO jobs (type, payload, status)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $type,
            json_encode($payload),
            self::STATUS_PENDING,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Get the next pending job from the queue.
     *
     * Marks the job as 'processing' and returns it. Uses database locking
     * to prevent multiple workers from processing the same job.
     *
     * @return array|null Job data or null if no pending jobs
     */
    public static function pop(): ?array
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();

        // Use transaction with row locking
        $db->beginTransaction();

        try {
            // Get next pending job (oldest first)
            if ($dbType === 'pgsql') {
                $stmt = $db->prepare("
                    SELECT id, type, payload, attempts, max_attempts
                    FROM jobs
                    WHERE status = ?
                    ORDER BY created_at ASC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                ");
            } else {
                // SQLite doesn't support SKIP LOCKED, so we'll use a simpler approach
                $stmt = $db->prepare("
                    SELECT id, type, payload, attempts, max_attempts
                    FROM jobs
                    WHERE status = ?
                    ORDER BY created_at ASC
                    LIMIT 1
                ");
            }

            $stmt->execute([self::STATUS_PENDING]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (! $job) {
                $db->rollBack();

                return null;
            }

            // Mark as processing
            $updateStmt = $db->prepare("
                UPDATE jobs
                SET status = ?, started_at = CURRENT_TIMESTAMP, attempts = attempts + 1
                WHERE id = ?
            ");
            $updateStmt->execute([self::STATUS_PROCESSING, $job['id']]);

            $db->commit();

            // Decode payload with error handling
            // Use a sentinel value to detect decode errors (since payload could be an empty array)
            $sentinel = new \stdClass();
            $decodedPayload = \PhpRss\Utils::safeJsonDecode($job['payload'], $sentinel, true);
            
            // If we got the sentinel, decoding failed
            if ($decodedPayload === $sentinel) {
                Logger::warning("Failed to decode job payload", [
                    'job_id' => $job['id'],
                    'job_type' => $job['type'],
                    'payload_preview' => substr($job['payload'], 0, 100),
                ]);
                $db->rollBack();
                
                return null;
            }
            
            // Ensure payload is an array
            if (! is_array($decodedPayload)) {
                Logger::warning("Job payload is not an array", [
                    'job_id' => $job['id'],
                    'job_type' => $job['type'],
                ]);
                $db->rollBack();
                
                return null;
            }
            
            $job['payload'] = $decodedPayload;

            return $job;
        } catch (\Exception $e) {
            $db->rollBack();
            Logger::exception($e, ['context' => 'job_queue_pop']);

            return null;
        }
    }

    /**
     * Mark a job as completed.
     *
     * @param int $jobId Job ID
     * @return void
     */
    public static function complete(int $jobId): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            UPDATE jobs
            SET status = ?, completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([self::STATUS_COMPLETED, $jobId]);
    }

    /**
     * Mark a job as failed.
     *
     * @param int $jobId Job ID
     * @param string $errorMessage Error message
     * @return void
     */
    public static function fail(int $jobId, string $errorMessage): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            UPDATE jobs
            SET status = ?, error_message = ?, completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([self::STATUS_FAILED, $errorMessage, $jobId]);
    }

    /**
     * Retry a failed job if it hasn't exceeded max attempts.
     *
     * @param int $jobId Job ID
     * @return bool True if job was reset to pending, false if max attempts exceeded
     */
    public static function retry(int $jobId): bool
    {
        $db = Database::getConnection();

        // Check if job can be retried
        $stmt = $db->prepare("
            SELECT attempts, max_attempts
            FROM jobs
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $job || $job['attempts'] >= $job['max_attempts']) {
            return false;
        }

        // Reset to pending
        $stmt = $db->prepare("
            UPDATE jobs
            SET status = ?, started_at = NULL, error_message = NULL
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_PENDING, $jobId]);

        return true;
    }

    /**
     * Get job statistics.
     *
     * @return array Statistics about jobs in the queue
     */
    public static function getStats(): array
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT 
                status,
                COUNT(*) as count
            FROM jobs
            GROUP BY status
        ");

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Clean up old completed and failed jobs.
     *
     * Removes jobs older than the specified number of days.
     *
     * @param int $daysOld Number of days to keep jobs (default: 7)
     * @return int Number of jobs deleted
     */
    public static function cleanup(int $daysOld = 7): int
    {
        $db = Database::getConnection();
        $dbType = Database::getDbType();

        if ($dbType === 'pgsql') {
            $stmt = $db->prepare("
                DELETE FROM jobs
                WHERE status IN (?, ?)
                AND completed_at < CURRENT_TIMESTAMP - INTERVAL '{$daysOld} days'
            ");
        } else {
            $stmt = $db->prepare("
                DELETE FROM jobs
                WHERE status IN (?, ?)
                AND completed_at < datetime('now', '-{$daysOld} days')
            ");
        }

        $stmt->execute([self::STATUS_COMPLETED, self::STATUS_FAILED]);

        return $stmt->rowCount();
    }
}
