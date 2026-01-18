<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Config;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset config cache before each test
        Config::reset();
        
        // Set test environment variables
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=1');
        putenv('DB_TYPE=sqlite');
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('DB_TYPE');
        Config::reset();
    }

    public function testGetReturnsDefaultValue(): void
    {
        $value = Config::get('nonexistent.key', 'default');
        $this->assertEquals('default', $value);
    }

    public function testGetReturnsConfigValue(): void
    {
        $appName = Config::get('app.name');
        $this->assertIsString($appName);
        $this->assertNotEmpty($appName);
    }

    public function testGetWithDotNotation(): void
    {
        $dbType = Config::get('database.type');
        $this->assertIsString($dbType);
    }

    public function testIsDevelopment(): void
    {
        putenv('APP_ENV=development');
        Config::reset();
        $this->assertTrue(Config::isDevelopment());
    }

    public function testIsProduction(): void
    {
        putenv('APP_ENV=production');
        Config::reset();
        $this->assertTrue(Config::isProduction());
    }

    public function testIsDebug(): void
    {
        putenv('APP_DEBUG=1');
        Config::reset();
        $this->assertTrue(Config::isDebug());
    }

    public function testEnvironmentVariableOverride(): void
    {
        putenv('DB_TYPE=pgsql');
        Config::reset();
        $dbType = Config::get('database.type');
        $this->assertEquals('pgsql', $dbType);
    }
}
