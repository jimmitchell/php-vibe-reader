<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Middleware\RateLimiter;
use PhpRss\Database;
use PhpRss\Config;

class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        Database::init();
        putenv('RATE_LIMITING_ENABLED=1');
        Config::reset();
    }

    protected function tearDown(): void
    {
        // Clean up rate limit entries
        $db = Database::getConnection();
        $db->exec("DELETE FROM rate_limits");
        putenv('RATE_LIMITING_ENABLED');
        Config::reset();
    }

    public function testRateLimiterAllowsRequestWithinLimit(): void
    {
        $key = 'test:' . time();
        $result = RateLimiter::check($key, 5, 60);
        $this->assertTrue($result);
    }

    public function testRateLimiterBlocksRequestExceedingLimit(): void
    {
        $key = 'test_limit:' . time();
        $maxRequests = 3; // Lower limit for faster test
        $window = 60;
        
        // Make requests up to the limit
        for ($i = 0; $i < $maxRequests; $i++) {
            $result = RateLimiter::check($key, $maxRequests, $window);
            $this->assertTrue($result, "Request $i should be allowed");
            usleep(100000); // Small delay to ensure different timestamps
        }
        
        // Next request should be blocked
        $result = RateLimiter::check($key, $maxRequests, $window);
        $this->assertFalse($result, "Request exceeding limit should be blocked");
    }

    public function testGetRemainingReturnsCorrectCount(): void
    {
        $key = 'test_remaining:' . time();
        $maxRequests = 10;
        $window = 60;
        
        // Make 3 requests
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::check($key, $maxRequests, $window);
            usleep(100000); // Small delay
        }
        
        $remaining = RateLimiter::getRemaining($key, $maxRequests, $window);
        // Should have 7 remaining (10 - 3)
        $this->assertGreaterThanOrEqual(6, $remaining, "Should have at least 6 remaining");
        $this->assertLessThanOrEqual(7, $remaining, "Should have at most 7 remaining");
    }

    public function testRateLimiterRespectsDisabledSetting(): void
    {
        // Temporarily disable rate limiting
        putenv('RATE_LIMITING_ENABLED=0');
        Config::reset();
        
        // Verify config is actually disabled
        $enabled = Config::get('rate_limiting.enabled', true);
        $this->assertFalse($enabled, "Rate limiting should be disabled in config");
        
        $key = 'test_disabled:' . time();
        // Even with 0 limit, should allow when disabled
        $result = RateLimiter::check($key, 0, 60);
        $this->assertTrue($result, "Should allow when rate limiting is disabled");
        
        // Restore
        putenv('RATE_LIMITING_ENABLED=1');
        Config::reset();
    }
}
