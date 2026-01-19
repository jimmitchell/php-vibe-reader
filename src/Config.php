<?php

namespace PhpRss;

/**
 * Configuration management class.
 *
 * Provides centralized configuration management with environment variable support,
 * validation, and default values. This is the single source of truth for all
 * application configuration.
 */
class Config
{
    /** @var array|null Cached configuration array */
    private static ?array $config = null;

    /**
     * Get a configuration value.
     *
     * @param string $key Configuration key (supports dot notation, e.g., 'database.host')
     * @param mixed $default Default value if key is not found
     * @return mixed Configuration value or default
     */
    public static function get(string $key, $default = null)
    {
        $config = self::load();

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (! isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Load configuration from environment variables and defaults.
     *
     * @return array Configuration array
     */
    private static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        self::$config = [
            'app' => [
                'name' => getenv('APP_NAME') ?: 'VibeReader',
                'env' => getenv('APP_ENV') ?: 'production',
                'debug' => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
                'url' => getenv('APP_URL') ?: 'http://localhost',
            ],

            'database' => [
                'type' => getenv('DB_TYPE') ?: 'sqlite',
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => getenv('DB_PORT') ?: '5432',
                'name' => getenv('DB_NAME') ?: 'vibereader',
                'user' => getenv('DB_USER') ?: 'vibereader',
                'password' => getenv('DB_PASSWORD') ?: 'vibereader',
                'path' => getenv('DB_PATH') ?: __DIR__ . '/../data/rss_reader.db',
            ],

            'session' => [
                'lifetime' => (int)(getenv('SESSION_LIFETIME') ?: '7200'),
                'regenerate_interval' => (int)(getenv('SESSION_REGENERATE_INTERVAL') ?: '1800'),
                'cookie_httponly' => true,
                'cookie_secure' => isset($_SERVER['HTTPS']),
                'cookie_samesite' => 'Strict',
            ],

            'csrf' => [
                'token_expires' => (int)(getenv('CSRF_TOKEN_EXPIRES') ?: '3600'),
                'field_name' => '_token',
            ],

            'feed' => [
                'fetch_timeout' => (int)(getenv('FEED_FETCH_TIMEOUT') ?: '30'),
                'fetch_connect_timeout' => (int)(getenv('FEED_FETCH_CONNECT_TIMEOUT') ?: '10'),
                'max_redirects' => (int)(getenv('FEED_MAX_REDIRECTS') ?: '10'),
                'user_agent' => getenv('FEED_USER_AGENT') ?: Version::getVersionString(),
                'retention_days' => (int)(getenv('FEED_RETENTION_DAYS') ?: '90'), // Keep items for 90 days
                'retention_count' => getenv('FEED_RETENTION_COUNT') ? (int)getenv('FEED_RETENTION_COUNT') : null, // Keep max N items per feed (null = unlimited)
            ],

            'upload' => [
                'max_file_size' => (int)(getenv('UPLOAD_MAX_SIZE') ?: '5242880'), // 5MB
                'allowed_mime_types' => ['application/xml', 'text/xml', 'application/octet-stream'],
                'allowed_extensions' => ['opml', 'xml'],
            ],

            'logging' => [
                'channel' => getenv('LOG_CHANNEL') ?: 'vibereader',
                'level' => getenv('LOG_LEVEL') ?: ((getenv('APP_ENV') ?: 'production') === 'production' ? 'info' : 'debug'),
                'path' => getenv('LOG_PATH') ?: __DIR__ . '/../logs/app.log',
                'max_files' => (int)(getenv('LOG_MAX_FILES') ?: '7'),
            ],

            'rate_limiting' => [
                'enabled' => filter_var(getenv('RATE_LIMITING_ENABLED') !== false ? getenv('RATE_LIMITING_ENABLED') : '1', FILTER_VALIDATE_BOOLEAN) !== false,
                'login_max_attempts' => (int)(getenv('RATE_LIMIT_LOGIN_ATTEMPTS') ?: '5'),
                'login_window' => (int)(getenv('RATE_LIMIT_LOGIN_WINDOW') ?: '900'), // 15 minutes
                'api_max_requests' => (int)(getenv('RATE_LIMIT_API_REQUESTS') ?: '100'),
                'api_window' => (int)(getenv('RATE_LIMIT_API_WINDOW') ?: '60'), // 1 minute
            ],

            'cache' => [
                'enabled' => filter_var(getenv('CACHE_ENABLED') !== false ? getenv('CACHE_ENABLED') : '1', FILTER_VALIDATE_BOOLEAN) !== false,
                'driver' => getenv('CACHE_DRIVER') ?: 'file', // 'file' or 'redis'
                'ttl' => (int)(getenv('CACHE_TTL') ?: '300'), // 5 minutes default
                'file' => [
                    'path' => getenv('CACHE_PATH') ?: (__DIR__ . '/../data/cache'),
                ],
                'redis' => [
                    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                    'port' => (int)(getenv('REDIS_PORT') ?: '6379'),
                    'password' => getenv('REDIS_PASSWORD') ?: null,
                    'database' => (int)(getenv('REDIS_DATABASE') ?: '0'),
                ],
            ],
            'jobs' => [
                'enabled' => filter_var(getenv('JOBS_ENABLED') !== false ? getenv('JOBS_ENABLED') : '0', FILTER_VALIDATE_BOOLEAN) !== false,
                'worker_sleep' => (int)(getenv('JOBS_WORKER_SLEEP') ?: '5'), // seconds
                'max_attempts' => (int)(getenv('JOBS_MAX_ATTEMPTS') ?: '3'),
                'cleanup_days' => (int)(getenv('JOBS_CLEANUP_DAYS') ?: '7'),
            ],
        ];

        return self::$config;
    }

    /**
     * Check if application is in production environment.
     *
     * @return bool True if production, false otherwise
     */
    public static function isProduction(): bool
    {
        return self::get('app.env') === 'production';
    }

    /**
     * Check if application is in development environment.
     *
     * @return bool True if development, false otherwise
     */
    public static function isDevelopment(): bool
    {
        return self::get('app.env') === 'development';
    }

    /**
     * Check if debug mode is enabled.
     *
     * @return bool True if debug enabled, false otherwise
     */
    public static function isDebug(): bool
    {
        return self::get('app.debug', false);
    }

    /**
     * Reset cached configuration (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$config = null;
    }
}
