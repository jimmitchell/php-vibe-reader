<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\FeedFetcher;
use PhpRss\Config;

class FeedFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure Config is initialized
        Config::reset();
    }

    public function testFetchRejectsLocalhost(): void
    {
        $this->expectException(\Exception::class);
        // localhost can be caught by either localhost pattern or private IP check
        $this->expectExceptionMessageMatches('/(localhost|private\/internal IP)/');
        
        FeedFetcher::fetch('http://localhost/feed');
    }

    public function testFetchRejects127001(): void
    {
        $this->expectException(\Exception::class);
        // 127.0.0.1 is caught by private IP range check (127.0.0.0/8)
        $this->expectExceptionMessage('private/internal IP');
        
        FeedFetcher::fetch('http://127.0.0.1/feed');
    }

    public function testFetchRejectsPrivateIPRange10(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('private/internal IP');
        
        FeedFetcher::fetch('http://10.0.0.1/feed');
    }

    public function testFetchRejectsPrivateIPRange172(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('private/internal IP');
        
        FeedFetcher::fetch('http://172.16.0.1/feed');
    }

    public function testFetchRejectsPrivateIPRange192(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('private/internal IP');
        
        FeedFetcher::fetch('http://192.168.1.1/feed');
    }

    public function testFetchRejectsLinkLocal169(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('private/internal IP');
        
        FeedFetcher::fetch('http://169.254.0.1/feed');
    }

    public function testFetchRejectsInvalidURL(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid URL format');
        
        FeedFetcher::fetch('not-a-valid-url');
    }

    public function testFetchRejectsURLWithoutHost(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid URL format');
        
        FeedFetcher::fetch('http:///feed');
    }

    public function testUpdateFeedReturnsFalseForNonExistentFeed(): void
    {
        $result = FeedFetcher::updateFeed(99999);
        $this->assertFalse($result);
    }

    // Note: Testing actual fetch() with real URLs would require:
    // 1. Mocking cURL (complex)
    // 2. Using a test HTTP server
    // 3. Making real HTTP requests (integration test)
    // 
    // The SSRF protection tests above verify the critical security functionality.
    // Full fetch() testing is better suited for integration tests with real feeds.
}
