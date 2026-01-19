<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Version;

class VersionTest extends TestCase
{
    public function testGetVersionReturnsVersionConstant(): void
    {
        $version = Version::getVersion();
        
        $this->assertEquals(Version::VERSION, $version);
        $this->assertNotEmpty($version);
    }

    public function testGetAppNameReturnsAppNameConstant(): void
    {
        $appName = Version::getAppName();
        
        $this->assertEquals(Version::APP_NAME, $appName);
        $this->assertEquals('VibeReader', $appName);
    }

    public function testGetVersionStringFormatsCorrectly(): void
    {
        $versionString = Version::getVersionString();
        
        $expected = Version::APP_NAME . '/' . Version::VERSION;
        $this->assertEquals($expected, $versionString);
        $this->assertStringContainsString('VibeReader', $versionString);
        $this->assertStringContainsString('/', $versionString);
    }

    public function testVersionConstantIsSet(): void
    {
        $this->assertIsString(Version::VERSION);
        $this->assertNotEmpty(Version::VERSION);
        // Should be in semantic versioning format (MAJOR.MINOR.PATCH)
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::VERSION);
    }

    public function testAppNameConstantIsSet(): void
    {
        $this->assertIsString(Version::APP_NAME);
        $this->assertEquals('VibeReader', Version::APP_NAME);
    }
}
