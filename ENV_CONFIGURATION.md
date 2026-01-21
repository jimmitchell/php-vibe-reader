# Environment Configuration Reference

Complete reference for all environment variables used by VibeReader.

## Application Settings

```bash
APP_NAME=VibeReader
APP_ENV=production          # production, development
APP_DEBUG=0                 # 0 = disabled, 1 = enabled
APP_URL=http://localhost
```

## Database Configuration

```bash
DB_TYPE=sqlite              # sqlite or pgsql
DB_HOST=localhost           # PostgreSQL only
DB_PORT=5432                # PostgreSQL only
DB_NAME=vibereader          # PostgreSQL only
DB_USER=vibereader          # PostgreSQL only
DB_PASSWORD=vibereader      # PostgreSQL only
DB_PATH=data/rss_reader.db  # SQLite only
```

## Session Configuration

```bash
SESSION_LIFETIME=7200                    # Seconds (2 hours)
SESSION_REGENERATE_INTERVAL=1800         # Seconds (30 minutes)
```

## CSRF Protection

```bash
CSRF_TOKEN_EXPIRES=3600                  # Seconds (1 hour)
```

## Feed Fetching

```bash
FEED_FETCH_TIMEOUT=30                    # Seconds
FEED_FETCH_CONNECT_TIMEOUT=10            # Seconds
FEED_MAX_REDIRECTS=10
FEED_USER_AGENT=                         # Optional, defaults to app version
FEED_RETENTION_DAYS=90                   # Days to keep feed items
FEED_RETENTION_COUNT=                    # Max items per feed (empty = unlimited)
```

## File Upload

```bash
UPLOAD_MAX_SIZE=5242880                  # Bytes (5MB)
```

## Logging

```bash
LOG_CHANNEL=vibereader
LOG_LEVEL=info                            # debug, info, warning, error
LOG_PATH=logs/app.log
LOG_MAX_FILES=7                           # Number of rotated log files to keep
```

## Rate Limiting

```bash
RATE_LIMITING_ENABLED=1                  # 0 = disabled, 1 = enabled
RATE_LIMIT_LOGIN_ATTEMPTS=5              # Max login attempts
RATE_LIMIT_LOGIN_WINDOW=900              # Seconds (15 minutes)
RATE_LIMIT_API_REQUESTS=100              # Max API requests
RATE_LIMIT_API_WINDOW=60                 # Seconds (1 minute)
```

## Caching

```bash
CACHE_ENABLED=1                           # 0 = disabled, 1 = enabled
CACHE_DRIVER=file                         # file or redis
CACHE_TTL=300                             # Seconds (5 minutes)
```

## HTML Sanitization

```bash
SANITIZATION_ENABLED=1                    # 0 = disabled, 1 = enabled (default: enabled)
```
CACHE_PATH=data/cache                     # File cache only
```

## Redis (Optional, for cache)

```bash
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=                           # Optional
REDIS_DATABASE=0
```

## Background Jobs

```bash
JOBS_ENABLED=0                            # 0 = disabled, 1 = enabled
JOBS_WORKER_SLEEP=5                       # Seconds between job checks
JOBS_MAX_ATTEMPTS=3                       # Max retries for failed jobs
JOBS_CLEANUP_DAYS=7                       # Days to keep job records
```

## Quick Setup

Copy `.env.example` to `.env` and adjust values as needed:

```bash
cp .env.example .env
```

Then edit `.env` with your preferred settings.

## Production Recommendations

```bash
APP_ENV=production
APP_DEBUG=0
SESSION_LIFETIME=7200
RATE_LIMITING_ENABLED=1
CACHE_ENABLED=1
JOBS_ENABLED=1                            # Enable for better performance
FEED_RETENTION_DAYS=90                    # Prevent database bloat
```

## Development Recommendations

```bash
APP_ENV=development
APP_DEBUG=1
LOG_LEVEL=debug
JOBS_ENABLED=0                            # Easier debugging
```
