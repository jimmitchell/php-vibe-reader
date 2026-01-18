<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Services\FeedService;
use PhpRss\Database;

class FeedServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // Initialize database for testing
        Database::init();
        // Ensure database schema exists
        Database::setup();
    }

    public function testVerifyFeedOwnershipReturnsFalseForNonExistentFeed(): void
    {
        $result = FeedService::verifyFeedOwnership(99999, 1);
        $this->assertFalse($result);
    }

    public function testVerifyItemOwnershipReturnsFalseForNonExistentItem(): void
    {
        $result = FeedService::verifyItemOwnership(99999, 1);
        $this->assertFalse($result);
    }

    public function testVerifyFolderOwnershipReturnsFalseForNonExistentFolder(): void
    {
        $result = FeedService::verifyFolderOwnership(99999, 1);
        $this->assertFalse($result);
    }

    // Note: Full integration tests would require database setup with test data
    // These tests verify the methods exist and handle non-existent records correctly
}
