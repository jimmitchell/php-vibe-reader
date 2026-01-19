<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Cache;
use PhpRss\Config;

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure cache is enabled for tests
        putenv('CACHE_ENABLED=1');
        putenv('CACHE_DRIVER=file');
        Config::reset();
        
        // Clear any existing cache
        Cache::clear('test_');
    }

    protected function tearDown(): void
    {
        // Clean up test cache entries
        Cache::clear('test_');
        putenv('CACHE_ENABLED');
        putenv('CACHE_DRIVER');
        Config::reset();
    }

    public function testCacheSetAndGet(): void
    {
        $key = 'test_key_' . uniqid();
        $value = ['test' => 'data', 'number' => 123];
        
        $result = Cache::set($key, $value, 60);
        $this->assertTrue($result);
        
        $cached = Cache::get($key);
        $this->assertEquals($value, $cached);
    }

    public function testCacheGetReturnsDefaultWhenNotFound(): void
    {
        $key = 'test_nonexistent_' . uniqid();
        $default = 'default_value';
        
        $result = Cache::get($key, $default);
        $this->assertEquals($default, $result);
    }

    public function testCacheHasReturnsTrueForExistingKey(): void
    {
        $key = 'test_has_' . uniqid();
        Cache::set($key, 'value', 60);
        
        $this->assertTrue(Cache::has($key));
    }

    public function testCacheHasReturnsFalseForNonExistentKey(): void
    {
        $key = 'test_not_has_' . uniqid();
        $this->assertFalse(Cache::has($key));
    }

    public function testCacheDeleteRemovesKey(): void
    {
        $key = 'test_delete_' . uniqid();
        Cache::set($key, 'value', 60);
        $this->assertTrue(Cache::has($key));
        
        Cache::delete($key);
        $this->assertFalse(Cache::has($key));
    }

    public function testCacheRememberExecutesCallbackWhenNotCached(): void
    {
        $key = 'test_remember_' . uniqid();
        $callbackExecuted = false;
        
        $result = Cache::remember($key, function() use (&$callbackExecuted) {
            $callbackExecuted = true;
            return 'computed_value';
        }, 60);
        
        $this->assertTrue($callbackExecuted);
        $this->assertEquals('computed_value', $result);
    }

    public function testCacheRememberReturnsCachedValue(): void
    {
        $key = 'test_remember_cached_' . uniqid();
        Cache::set($key, 'cached_value', 60);
        
        $callbackExecuted = false;
        $result = Cache::remember($key, function() use (&$callbackExecuted) {
            $callbackExecuted = true;
            return 'new_value';
        }, 60);
        
        $this->assertFalse($callbackExecuted);
        $this->assertEquals('cached_value', $result);
    }

    public function testCacheInvalidateFeed(): void
    {
        $feedId = 123;
        Cache::set("feed_counts:{$feedId}", ['count' => 10], 60);
        Cache::set("feed_metadata:{$feedId}", ['title' => 'Test'], 60);
        
        Cache::invalidateFeed($feedId);
        
        $this->assertFalse(Cache::has("feed_counts:{$feedId}"));
        $this->assertFalse(Cache::has("feed_metadata:{$feedId}"));
    }

    public function testCacheInvalidateUserFeeds(): void
    {
        $userId = 456;
        Cache::set("user_feeds:{$userId}", ['feeds' => []], 60);
        Cache::set("user_feeds:{$userId}:hide_no_unread", ['feeds' => []], 60);
        
        Cache::invalidateUserFeeds($userId);
        
        $this->assertFalse(Cache::has("user_feeds:{$userId}"));
        $this->assertFalse(Cache::has("user_feeds:{$userId}:hide_no_unread"));
    }
}
