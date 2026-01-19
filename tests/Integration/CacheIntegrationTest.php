<?php

namespace PhpRss\Tests\Integration;

use PhpRss\Services\FeedService;
use PhpRss\Cache;
use PhpRss\Config;
use PhpRss\Database;

/**
 * Integration tests for cache functionality.
 * 
 * Tests that caching is properly integrated with FeedService and
 * that cache invalidation works correctly.
 */
class CacheIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure cache is enabled for these tests
        putenv('CACHE_ENABLED=1');
        putenv('CACHE_DRIVER=file');
        Config::reset();
        
        // Clear cache before each test
        Cache::clear('user_feeds:');
        Cache::clear('feed_counts:');
        Cache::clear('feed_metadata:');
    }

    protected function tearDown(): void
    {
        // Clear cache after each test
        Cache::clear('user_feeds:');
        Cache::clear('feed_counts:');
        Cache::clear('feed_metadata:');
        
        parent::tearDown();
    }

    public function testFeedServiceUsesCache(): void
    {
        $this->loginTestUser();

        // Create test feeds
        $feed1Id = $this->createTestFeed($this->testUserId, 'Feed 1', 'https://example.com/feed1_' . uniqid() . '.xml');
        $feed2Id = $this->createTestFeed($this->testUserId, 'Feed 2', 'https://example.com/feed2_' . uniqid() . '.xml');
        $this->createTestFeedItem($feed1Id, 'Item 1', false, $this->testUserId);
        $this->createTestFeedItem($feed1Id, 'Item 2', true, $this->testUserId);

        // First call - should populate cache
        $feeds1 = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(2, $feeds1);

        // Verify cache was created
        $cacheKey = "user_feeds:{$this->testUserId}";
        $this->assertTrue(Cache::has($cacheKey), 'Cache should contain feeds after first call');

        // Second call - should use cache (even if we add items to database)
        $this->createTestFeedItem($feed2Id, 'New Item', false, $this->testUserId);
        $feeds2 = FeedService::getFeedsForUser($this->testUserId, false);
        
        // Should still return cached data (2 feeds, not reflecting new item count yet)
        $this->assertCount(2, $feeds2);
        
        // Verify it's the same cached data (item counts haven't changed)
        $this->assertEquals($feeds1[0]['item_count'], $feeds2[0]['item_count']);
    }

    public function testCacheInvalidationOnMarkAsRead(): void
    {
        $this->loginTestUser();

        $feedId = $this->createTestFeed($this->testUserId);
        $itemId = $this->createTestFeedItem($feedId, 'Test Item', false, $this->testUserId);

        // Get feeds to populate cache
        $feedsBefore = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(1, $feedsBefore);
        $unreadCountBefore = $feedsBefore[0]['unread_count'];

        // Verify cache exists
        $cacheKey = "user_feeds:{$this->testUserId}";
        $this->assertTrue(Cache::has($cacheKey));

        // Mark item as read (this should invalidate cache)
        $db = Database::getConnection();
        $dbType = Database::getDbType();
        $insertSql = $dbType === 'pgsql'
            ? "INSERT INTO read_items (user_id, feed_item_id) VALUES (?, ?) ON CONFLICT (user_id, feed_item_id) DO NOTHING"
            : "INSERT OR IGNORE INTO read_items (user_id, feed_item_id) VALUES (?, ?)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$this->testUserId, $itemId]);

        // Manually invalidate cache (as FeedController would)
        FeedService::invalidateUserCache($this->testUserId);

        // Verify cache was cleared
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after marking as read');

        // Get feeds again - should fetch fresh data
        $feedsAfter = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(1, $feedsAfter);
        
        // Unread count should be updated (decreased by 1)
        $unreadCountAfter = $feedsAfter[0]['unread_count'];
        $this->assertEquals($unreadCountBefore - 1, $unreadCountAfter, 'Unread count should decrease after marking as read');
    }

    public function testCacheInvalidationOnFeedDelete(): void
    {
        $this->loginTestUser();

        $feedId = $this->createTestFeed($this->testUserId);

        // Get feeds to populate cache
        $feedsBefore = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(1, $feedsBefore);

        // Verify cache exists
        $cacheKey = "user_feeds:{$this->testUserId}";
        $this->assertTrue(Cache::has($cacheKey));

        // Delete feed (this should invalidate cache)
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM feeds WHERE id = ? AND user_id = ?");
        $stmt->execute([$feedId, $this->testUserId]);

        // Manually invalidate cache (as FeedController would)
        FeedService::invalidateUserCache($this->testUserId);

        // Verify cache was cleared
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after deleting feed');

        // Get feeds again - should fetch fresh data
        $feedsAfter = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(0, $feedsAfter, 'Feed should be removed after deletion');
    }

    public function testCacheRespectsHideNoUnreadPreference(): void
    {
        $this->loginTestUser();

        $uniqueId = uniqid();
        $feed1Id = $this->createTestFeed($this->testUserId, 'Feed with unread', "https://example.com/unread_{$uniqueId}.xml");
        $feed2Id = $this->createTestFeed($this->testUserId, 'Feed without unread', "https://example.com/read_{$uniqueId}.xml");
        
        $this->createTestFeedItem($feed1Id, 'Unread Item', false, $this->testUserId);
        $this->createTestFeedItem($feed2Id, 'Read Item', true, $this->testUserId);

        // Get feeds without hiding
        $feedsAll = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(2, $feedsAll);

        // Get feeds with hide_no_unread
        $feedsFiltered = FeedService::getFeedsForUser($this->testUserId, true);
        $this->assertCount(1, $feedsFiltered, 'Should only show feed with unread items');

        // Verify different cache keys are used
        $cacheKeyAll = "user_feeds:{$this->testUserId}";
        $cacheKeyFiltered = "user_feeds:{$this->testUserId}:hide_no_unread";
        
        $this->assertTrue(Cache::has($cacheKeyAll));
        $this->assertTrue(Cache::has($cacheKeyFiltered));
    }

    public function testCacheDisabledBypassesCache(): void
    {
        // Clear any existing cache first
        Cache::clear('user_feeds:');

        // Disable cache
        putenv('CACHE_ENABLED=0');
        Config::reset();

        $this->loginTestUser();
        $feedId = $this->createTestFeed($this->testUserId);

        // Get feeds - should not use cache (FeedService checks Config directly)
        $feeds = FeedService::getFeedsForUser($this->testUserId, false);
        $this->assertCount(1, $feeds);

        // Verify cache is disabled in config
        // Note: When cache is disabled, FeedService bypasses Cache::remember() entirely
        // The test verifies that feeds are still returned correctly without caching
        $cacheEnabled = Config::get('cache.enabled', true);
        $this->assertFalse($cacheEnabled, 'Cache should be disabled in config');
        
        // Verify that even if we try to use cache, NullCache returns false for has()
        $cacheKey = "user_feeds:{$this->testUserId}";
        $this->assertFalse(Cache::has($cacheKey), 'NullCache should return false for has()');

        // Re-enable for other tests
        putenv('CACHE_ENABLED=1');
        Config::reset();
    }

    public function testCacheRememberWorksCorrectly(): void
    {
        $this->loginTestUser();

        $key = 'test_remember_' . uniqid();
        $callCount = 0;

        // First call - should execute callback
        $result1 = Cache::remember($key, function() use (&$callCount) {
            $callCount++;
            return ['data' => 'value1'];
        }, 60);

        $this->assertEquals(1, $callCount, 'Callback should be executed once');
        $this->assertEquals(['data' => 'value1'], $result1);

        // Second call - should use cache
        $result2 = Cache::remember($key, function() use (&$callCount) {
            $callCount++;
            return ['data' => 'value2'];
        }, 60);

        $this->assertEquals(1, $callCount, 'Callback should not be executed again');
        $this->assertEquals(['data' => 'value1'], $result2, 'Should return cached value');
    }
}
