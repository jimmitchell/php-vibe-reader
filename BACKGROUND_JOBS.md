# Background Jobs System

VibeReader includes a background job queue system for processing feed updates and cleanup operations asynchronously, improving performance and user experience.

## Features

- **Database-backed queue**: Works with both SQLite and PostgreSQL
- **Automatic retries**: Failed jobs are retried up to 3 times (configurable)
- **Job status tracking**: Monitor pending, processing, completed, and failed jobs
- **Feed fetching**: Update feeds in the background without blocking user requests
- **Item cleanup**: Automatically remove old feed items based on retention policies

## Configuration

Add these settings to your `.env` file:

```bash
# Enable background jobs (0 = disabled, 1 = enabled)
JOBS_ENABLED=0

# Worker sleep interval in seconds (how long to wait when no jobs available)
JOBS_WORKER_SLEEP=5

# Maximum retry attempts for failed jobs
JOBS_MAX_ATTEMPTS=3

# Days to keep completed/failed job records
JOBS_CLEANUP_DAYS=7

# Feed retention settings
FEED_RETENTION_DAYS=90        # Keep items for 90 days
FEED_RETENTION_COUNT=         # Keep max N items per feed (empty = unlimited)
```

## Setting Up the Worker

### Option 1: Cron Job (Recommended)

You need **two** cron jobs for the complete system:

1. **Scheduler** - Queues feed refresh jobs (runs every 15-30 minutes):
```bash
*/15 * * * * cd /path/to/vibereader && php scheduler.php
```

2. **Worker** - Processes queued jobs (runs every 1-5 minutes):
```bash
*/5 * * * * cd /path/to/vibereader && php worker.php
```

Or process worker more frequently:

```bash
*/1 * * * * cd /path/to/vibereader && php worker.php
```

**For Docker containers**, you can add these to your host's crontab:

```bash
# Schedule feed refreshes every 15 minutes
*/15 * * * * docker exec vibereader php /var/www/html/scheduler.php

# Process jobs every 5 minutes
*/5 * * * * docker exec vibereader php /var/www/html/worker.php
```

### Option 2: Daemon Mode

Run the worker as a background daemon:

```bash
php worker.php --daemon
```

This will run continuously until stopped (Ctrl+C).

### Option 3: Manual Execution

Run the worker manually when needed:

```bash
php worker.php
```

This processes all available jobs once and exits.

## Worker Options

```bash
# Process up to 10 jobs
php worker.php --max-jobs=10

# Custom sleep interval (seconds)
php worker.php --sleep=10

# Run as daemon
php worker.php --daemon

# Combine options
php worker.php --daemon --sleep=5
```

## Job Types

### Fetch Feed (`fetch_feed`)

Updates a feed by fetching the latest content. Automatically queued when:
- User manually refreshes a feed (if `JOBS_ENABLED=1`)
- Background refresh is triggered

**Payload:**
```json
{
  "feed_id": 123
}
```

### Cleanup Items (`cleanup_items`)

Removes old feed items based on retention policies.

**Payload:**
```json
{
  "feed_id": 123,              // Optional: specific feed, null for all
  "retention_days": 90,        // Optional: override config
  "retention_count": 100       // Optional: override config
}
```

## API Endpoints

### Get Job Statistics

```http
GET /api/jobs/stats
```

Returns:
```json
{
  "pending": 5,
  "processing": 1,
  "completed": 150,
  "failed": 2
}
```

### Queue Cleanup Job

```http
POST /api/jobs/cleanup
Content-Type: application/x-www-form-urlencoded

feed_id=123&retention_days=30
```

Returns:
```json
{
  "success": true,
  "data": {
    "job_id": 456,
    "message": "Cleanup job queued"
  }
}
```

## Scheduling Cleanup

### Via Cron

Add to your crontab to run cleanup daily:

```bash
# Run cleanup at 2 AM daily
0 2 * * * cd /path/to/vibereader && php -r "require 'vendor/autoload.php'; \PhpRss\Queue\JobQueue::push(\PhpRss\Queue\JobQueue::TYPE_CLEANUP_ITEMS, []);"
```

### Via API

Call the cleanup endpoint programmatically or via a scheduled HTTP request.

## Monitoring

### Check Queue Status

Use the API endpoint to monitor job queue:

```bash
curl http://localhost/api/jobs/stats
```

### View Jobs in Database

```sql
-- SQLite
SELECT status, COUNT(*) FROM jobs GROUP BY status;

-- PostgreSQL
SELECT status, COUNT(*) FROM jobs GROUP BY status;
```

### Clean Up Old Jobs

The worker automatically cleans up old completed/failed jobs based on `JOBS_CLEANUP_DAYS`. You can also manually clean up:

```php
$deleted = \PhpRss\Queue\JobQueue::cleanup(7); // Remove jobs older than 7 days
```

## Troubleshooting

### Jobs Not Processing

1. Check that `JOBS_ENABLED=1` in your `.env` file
2. Verify both the scheduler and worker are running (check cron logs or process list)
3. Check job status in database: `SELECT * FROM jobs WHERE status = 'pending'`
4. Verify cron jobs are set up correctly (both scheduler and worker)

### Feeds Not Refreshing Automatically

1. **Check scheduler is running**: The scheduler script (`scheduler.php`) must be running via cron to queue refresh jobs
2. **Check worker is running**: The worker script (`worker.php`) must be running via cron to process queued jobs
3. **Check refresh interval**: Default is 15 minutes - feeds won't refresh if they were fetched more recently
4. **Check for duplicate jobs**: The scheduler skips feeds that already have pending jobs
5. **Verify database connection**: Both scripts need database access

### Testing the Scheduler

Run manually to test:

```bash
# Queue refresh jobs for feeds that need updating
php scheduler.php

# Process the queued jobs
php worker.php
```

### Docker-Specific Issues

If running in Docker, ensure:
- Both scripts are accessible in the container (mounted volumes)
- Cron jobs use `docker exec vibereader` to run scripts inside the container
- Environment variables are set correctly in `docker-compose.yml`
4. Review application logs for errors

### Jobs Failing

1. Check the `error_message` column in the `jobs` table
2. Review application logs for exceptions
3. Verify feed URLs are accessible
4. Check database connectivity

### Worker Not Starting

1. Verify PHP CLI is available: `php --version`
2. Check file permissions on `worker.php`
3. Verify database connection works
4. Check PHP error logs

## Performance Tips

1. **Adjust worker frequency**: More frequent workers = faster processing but more database queries
2. **Batch processing**: Use `--max-jobs` to limit jobs per run
3. **Sleep interval**: Increase `JOBS_WORKER_SLEEP` if you have many workers to reduce database load
4. **Cleanup frequency**: Run cleanup jobs during off-peak hours

## Security Notes

- Job queue endpoints require authentication
- CSRF protection is enforced for state-changing operations
- Job payloads are stored as JSON in the database (ensure database is secured)
- Failed jobs may contain error messages - review logs regularly

## Migration from Synchronous to Background Jobs

1. Set `JOBS_ENABLED=0` initially (synchronous mode)
2. Set up the worker cron job
3. Test with a few feeds
4. Set `JOBS_ENABLED=1` to enable background processing
5. Monitor job statistics to ensure proper operation
