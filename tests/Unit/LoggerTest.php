<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Logger;
use PhpRss\Config;

class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        // Set test environment
        putenv('LOG_LEVEL=debug');
        putenv('LOG_PATH=logs/test.log');
        Config::reset();
    }

    protected function tearDown(): void
    {
        putenv('LOG_LEVEL');
        putenv('LOG_PATH');
        Config::reset();
    }

    public function testLoggerInstanceExists(): void
    {
        $logger = Logger::getLogger();
        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function testDebugLogging(): void
    {
        // This should not throw an exception
        Logger::debug('Test debug message', ['context' => 'test']);
        $this->assertTrue(true);
    }

    public function testInfoLogging(): void
    {
        Logger::info('Test info message', ['context' => 'test']);
        $this->assertTrue(true);
    }

    public function testWarningLogging(): void
    {
        Logger::warning('Test warning message', ['context' => 'test']);
        $this->assertTrue(true);
    }

    public function testErrorLogging(): void
    {
        Logger::error('Test error message', ['context' => 'test']);
        $this->assertTrue(true);
    }

    public function testExceptionLogging(): void
    {
        $exception = new \Exception('Test exception');
        Logger::exception($exception, ['context' => 'test']);
        $this->assertTrue(true);
    }
}
