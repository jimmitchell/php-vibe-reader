<?php

namespace PhpRss;

/**
 * Application version information.
 * 
 * This class provides a single source of truth for the application version.
 * Update the VERSION constant here to change the version throughout the application.
 */
class Version
{
    /**
     * Application version in semantic versioning format (MAJOR.MINOR.PATCH)
     * 
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * Application name
     * 
     * @var string
     */
    public const APP_NAME = 'VibeReader';

    /**
     * Get the full application version string.
     * 
     * @return string Version string (e.g., "VibeReader/1.0.0")
     */
    public static function getVersionString(): string
    {
        return self::APP_NAME . '/' . self::VERSION;
    }

    /**
     * Get the version number only.
     * 
     * @return string Version number (e.g., "1.0.0")
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Get the application name.
     * 
     * @return string Application name
     */
    public static function getAppName(): string
    {
        return self::APP_NAME;
    }
}
