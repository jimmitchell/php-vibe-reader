# Setting Up Cron Jobs for Scheduled Feed Refreshes

This guide explains how to set up cron jobs to automatically refresh feeds in your VibeReader Docker container.

## Overview

You need **two cron jobs**:
1. **Scheduler** - Queues feed refresh jobs (runs every 15 minutes)
2. **Worker** - Processes queued jobs (runs every 5 minutes)

## Step-by-Step Instructions

### Step 1: Open Your Crontab

On your host machine (not inside Docker), open your crontab for editing:

```bash
crontab -e
```

If this is your first time, you may be asked to choose an editor. Choose your preferred editor (nano is usually the easiest).

### Step 2: Add the Cron Jobs

Add these two lines to your crontab file:

```bash
# Schedule feed refreshes every 15 minutes (queues jobs)
*/15 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/scheduler.php >> /tmp/vibereader-scheduler.log 2>&1

# Process queued jobs every 5 minutes (executes the jobs)
*/5 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/worker.php >> /tmp/vibereader-worker.log 2>&1
```

**Important Notes:**
- The `PATH=/usr/local/bin:/usr/bin:/bin` ensures Docker is found (cron has a minimal PATH)
- The `>> /tmp/vibereader-*.log 2>&1` redirects output to log files for debugging
- Adjust the timing (`*/15` and `*/5`) if you want different intervals

### Step 3: Save and Exit

- **nano**: Press `Ctrl+X`, then `Y`, then `Enter`
- **vim**: Press `Esc`, type `:wq`, then `Enter`
- **Other editors**: Follow their save/exit instructions

### Step 4: Verify the Cron Jobs

Check that your cron jobs were added:

```bash
crontab -l
```

You should see both lines listed.

### Step 5: Test the Scripts Manually

Before waiting for cron, test that the scripts work:

```bash
# Test scheduler (should queue jobs for feeds that need refreshing)
docker exec vibereader php /var/www/html/scheduler.php

# Test worker (should process any queued jobs)
docker exec vibereader php /var/www/html/worker.php
```

### Step 6: Monitor the Logs

Check the log files to see if cron is running the jobs:

```bash
# View scheduler logs
tail -f /tmp/vibereader-scheduler.log

# View worker logs
tail -f /tmp/vibereader-worker.log
```

## Alternative: Different Intervals

You can adjust the refresh frequency:

### More Frequent Refreshes (every 5 minutes)
```bash
*/5 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/scheduler.php >> /tmp/vibereader-scheduler.log 2>&1
*/1 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/worker.php >> /tmp/vibereader-worker.log 2>&1
```

### Less Frequent Refreshes (every 30 minutes)
```bash
*/30 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/scheduler.php >> /tmp/vibereader-scheduler.log 2>&1
*/5 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/worker.php >> /tmp/vibereader-worker.log 2>&1
```

## Custom Refresh Interval

You can change how often feeds are refreshed by passing the `--interval` parameter to the scheduler:

```bash
# Refresh feeds that haven't been updated in 30 minutes
*/15 * * * * PATH=/usr/local/bin:/usr/bin:/bin && docker exec vibereader php /var/www/html/scheduler.php --interval=30 >> /tmp/vibereader-scheduler.log 2>&1
```

## Troubleshooting

### Cron Jobs Not Running

1. **Check cron service is running:**
   ```bash
   # macOS
   sudo launchctl list | grep cron
   
   # Linux
   sudo systemctl status cron
   ```

2. **Check cron logs:**
   ```bash
   # macOS
   grep CRON /var/log/system.log
   
   # Linux
   grep CRON /var/log/syslog
   ```

3. **Verify Docker is accessible:**
   ```bash
   which docker
   docker exec vibereader php --version
   ```

### Scripts Not Working

1. **Check container name:**
   ```bash
   docker ps | grep vibereader
   ```
   Make sure the container name matches (should be `vibereader`)

2. **Test scripts manually:**
   ```bash
   docker exec vibereader php /var/www/html/scheduler.php
   docker exec vibereader php /var/www/html/worker.php
   ```

3. **Check environment variables:**
   ```bash
   docker exec vibereader env | grep JOBS
   ```
   Should show `JOBS_ENABLED=1`

### Jobs Not Processing

1. **Check job queue status:**
   ```bash
   docker exec vibereader php -r "require '/var/www/html/vendor/autoload.php'; \PhpRss\Database::init(); print_r(\PhpRss\Queue\JobQueue::getStats());"
   ```

2. **Check for pending jobs:**
   ```bash
   docker exec -it vibereader psql -U vibereader -d vibereader -c "SELECT status, COUNT(*) FROM jobs GROUP BY status;"
   ```

## Removing Cron Jobs

To remove the cron jobs:

```bash
crontab -e
```

Then delete the two lines you added and save.

Or remove all cron jobs:
```bash
crontab -r
```

## Summary

After setting up these cron jobs:
- **Scheduler** runs every 15 minutes and queues refresh jobs for feeds that need updating
- **Worker** runs every 5 minutes and processes the queued jobs
- Your feeds will automatically refresh in the background
- Manual refreshes still work immediately (they bypass the queue)
