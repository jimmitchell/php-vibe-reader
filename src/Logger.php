<?php

namespace PhpRss;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

/**
 * Application logger using Monolog (PSR-3 compliant).
 *
 * Provides structured logging with different log levels and automatic
 * log file rotation. This replaces basic error_log() calls throughout
 * the application.
 */
class Logger
{
    /** @var MonologLogger|null Singleton logger instance */
    private static ?MonologLogger $logger = null;

    /**
     * Get the logger instance.
     *
     * Creates and configures a Monolog logger if it doesn't exist.
     *
     * @return MonologLogger The logger instance
     */
    public static function getLogger(): MonologLogger
    {
        if (self::$logger === null) {
            self::initialize();
        }

        return self::$logger;
    }

    /**
     * Initialize the logger with handlers and formatters.
     *
     * @return void
     */
    private static function initialize(): void
    {
        $config = Config::get('logging');
        $channel = $config['channel'] ?? 'vibereader';
        $level = self::getLogLevel($config['level'] ?? 'info');
        $logPath = $config['path'] ?? __DIR__ . '/../logs/app.log';
        $maxFiles = $config['max_files'] ?? 7;

        self::$logger = new MonologLogger($channel);

        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Create rotating file handler (keeps N days of logs)
        $fileHandler = new RotatingFileHandler($logPath, $maxFiles, $level);

        // Set format: [YYYY-MM-DD HH:MM:SS] CHANNEL.LEVEL: MESSAGE CONTEXT
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $fileHandler->setFormatter($formatter);

        self::$logger->pushHandler($fileHandler);

        // In development, also log to stderr for immediate visibility
        if (Config::isDevelopment()) {
            $stderrHandler = new StreamHandler('php://stderr', $level);
            $stderrHandler->setFormatter($formatter);
            self::$logger->pushHandler($stderrHandler);
        }
    }

    /**
     * Convert string log level to Monolog constant.
     *
     * @param string $level Log level string (debug, info, warning, error, critical)
     * @return int Monolog log level constant
     */
    private static function getLogLevel(string $level): int
    {
        return match(strtolower($level)) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'notice' => MonologLogger::NOTICE,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            'critical' => MonologLogger::CRITICAL,
            'alert' => MonologLogger::ALERT,
            'emergency' => MonologLogger::EMERGENCY,
            default => MonologLogger::INFO,
        };
    }

    /**
     * Log a debug message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getLogger()->debug($message, $context);
    }

    /**
     * Log an info message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::getLogger()->info($message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getLogger()->warning($message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::getLogger()->error($message, $context);
    }

    /**
     * Log an exception.
     *
     * @param \Throwable $exception The exception to log
     * @param array $context Additional context data
     * @return void
     */
    public static function exception(\Throwable $exception, array $context = []): void
    {
        self::getLogger()->error(
            sprintf(
                '%s: %s in %s:%d',
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ),
            array_merge($context, [
                'exception' => $exception,
                'trace' => $exception->getTraceAsString(),
            ])
        );
    }
}
