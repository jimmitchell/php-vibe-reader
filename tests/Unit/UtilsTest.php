<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Utils;

class UtilsTest extends TestCase
{
    public function testFormatDateForJsonWithValidDate(): void
    {
        $result = Utils::formatDateForJson('2024-01-15 12:30:45');
        
        $this->assertNotNull($result);
        $this->assertStringStartsWith('2024-01-15T12:30:45', $result);
        $this->assertStringEndsWith('Z', $result);
    }

    public function testFormatDateForJsonWithNull(): void
    {
        $result = Utils::formatDateForJson(null);
        
        $this->assertNull($result);
    }

    public function testFormatDateForJsonWithEmptyString(): void
    {
        $result = Utils::formatDateForJson('');
        
        $this->assertNull($result);
    }

    public function testFormatDateForJsonWithInvalidDate(): void
    {
        $result = Utils::formatDateForJson('not-a-date');
        
        // Should return null for invalid dates
        $this->assertNull($result);
    }

    public function testFormatDateForJsonFormatsAsISO8601(): void
    {
        $result = Utils::formatDateForJson('2024-12-25 23:59:59');
        
        // Should be in ISO 8601 format with Z suffix (UTC)
        $this->assertEquals('2024-12-25T23:59:59Z', $result);
    }

    public function testFormatDatesForJsonWithSingleDateField(): void
    {
        $data = [
            'id' => 1,
            'title' => 'Test',
            'published_at' => '2024-01-15 12:30:45'
        ];
        
        $result = Utils::formatDatesForJson($data);
        
        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test', $result['title']);
        $this->assertStringStartsWith('2024-01-15T12:30:45', $result['published_at']);
        $this->assertStringEndsWith('Z', $result['published_at']);
    }

    public function testFormatDatesForJsonWithMultipleDateFields(): void
    {
        $data = [
            'id' => 1,
            'published_at' => '2024-01-15 12:30:45',
            'created_at' => '2024-01-10 10:00:00',
            'last_fetched' => '2024-01-20 15:45:30'
        ];
        
        $result = Utils::formatDatesForJson($data);
        
        $this->assertStringEndsWith('Z', $result['published_at']);
        $this->assertStringEndsWith('Z', $result['created_at']);
        $this->assertStringEndsWith('Z', $result['last_fetched']);
    }

    public function testFormatDatesForJsonWithMissingDateFields(): void
    {
        $data = [
            'id' => 1,
            'title' => 'Test',
            'published_at' => '2024-01-15 12:30:45'
        ];
        
        $result = Utils::formatDatesForJson($data);
        
        // Should not add fields that don't exist
        $this->assertArrayNotHasKey('created_at', $result);
        $this->assertArrayNotHasKey('last_fetched', $result);
    }

    public function testFormatDatesForJsonWithNullDateField(): void
    {
        $data = [
            'id' => 1,
            'published_at' => null,
            'created_at' => '2024-01-15 12:30:45'
        ];
        
        $result = Utils::formatDatesForJson($data);
        
        $this->assertNull($result['published_at']);
        $this->assertStringEndsWith('Z', $result['created_at']);
    }

    public function testFormatDatesForJsonWithCustomDateFields(): void
    {
        $data = [
            'id' => 1,
            'custom_date' => '2024-01-15 12:30:45',
            'another_date' => '2024-01-20 15:45:30'
        ];
        
        $result = Utils::formatDatesForJson($data, ['custom_date', 'another_date']);
        
        $this->assertStringEndsWith('Z', $result['custom_date']);
        $this->assertStringEndsWith('Z', $result['another_date']);
    }

    public function testFormatDatesForJsonPreservesNonDateFields(): void
    {
        $data = [
            'id' => 1,
            'title' => 'Test Title',
            'content' => 'Test Content',
            'published_at' => '2024-01-15 12:30:45'
        ];
        
        $result = Utils::formatDatesForJson($data);
        
        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test Title', $result['title']);
        $this->assertEquals('Test Content', $result['content']);
        $this->assertStringEndsWith('Z', $result['published_at']);
    }
}
