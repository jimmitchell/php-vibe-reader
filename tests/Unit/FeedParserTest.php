<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\FeedParser;

class FeedParserTest extends TestCase
{
    public function testDetectFeedTypeRSS(): void
    {
        $rssContent = '<?xml version="1.0"?><rss version="2.0"><channel><title>Test Feed</title></channel></rss>';
        $this->assertEquals('rss', FeedParser::detectFeedType($rssContent));
    }

    public function testDetectFeedTypeRDF(): void
    {
        $rdfContent = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><channel><title>Test Feed</title></channel></rdf:RDF>';
        $this->assertEquals('rss', FeedParser::detectFeedType($rdfContent));
    }

    public function testDetectFeedTypeAtom(): void
    {
        $atomContent = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test Feed</title></feed>';
        $this->assertEquals('atom', FeedParser::detectFeedType($atomContent));
    }

    public function testDetectFeedTypeJSONFeed(): void
    {
        $jsonContent = json_encode([
            'version' => 'https://jsonfeed.org/version/1',
            'title' => 'Test Feed',
            'items' => []
        ]);
        $this->assertEquals('json', FeedParser::detectFeedType($jsonContent));
    }

    public function testDetectFeedTypeJSONFeedWithVersion1(): void
    {
        $jsonContent = json_encode([
            'version' => '1',
            'title' => 'Test Feed',
            'items' => []
        ]);
        $this->assertEquals('json', FeedParser::detectFeedType($jsonContent));
    }

    public function testDetectFeedTypeJSONFeedWithTitleAndItems(): void
    {
        $jsonContent = json_encode([
            'title' => 'Test Feed',
            'items' => []
        ]);
        $this->assertEquals('json', FeedParser::detectFeedType($jsonContent));
    }

    public function testDetectFeedTypeDefaultsToRSS(): void
    {
        $unknownContent = 'Some random content';
        $this->assertEquals('rss', FeedParser::detectFeedType($unknownContent));
    }

    public function testDetectFeedTypePrioritizesRSSOverAtom(): void
    {
        // RSS feed that contains '<feed' string in content (not as root element)
        $rssWithFeedString = '<?xml version="1.0"?><rss version="2.0"><channel><title>Test</title><description>Visit feed.example.com</description></channel></rss>';
        $this->assertEquals('rss', FeedParser::detectFeedType($rssWithFeedString));
    }

    public function testParseRSSFeed(): void
    {
        $rssContent = '<?xml version="1.0"?>
        <rss version="2.0">
            <channel>
                <title>Test RSS Feed</title>
                <description>Test Description</description>
                <link>https://example.com</link>
                <item>
                    <title>Test Item</title>
                    <link>https://example.com/item1</link>
                    <description>Item description</description>
                    <pubDate>Mon, 01 Jan 2024 12:00:00 GMT</pubDate>
                </item>
            </channel>
        </rss>';
        
        $result = FeedParser::parse('https://example.com/feed', $rssContent);
        
        $this->assertIsArray($result);
        $this->assertEquals('Test RSS Feed', $result['title']);
        $this->assertEquals('Test Description', $result['description']);
        $this->assertEquals('https://example.com', $result['link']);
        $this->assertIsArray($result['items']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Test Item', $result['items'][0]['title']);
    }

    public function testParseAtomFeed(): void
    {
        $atomContent = '<?xml version="1.0"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
            <title>Test Atom Feed</title>
            <subtitle>Test Description</subtitle>
            <link href="https://example.com"/>
            <entry>
                <title>Test Entry</title>
                <link href="https://example.com/entry1"/>
                <summary>Entry summary</summary>
                <published>2024-01-01T12:00:00Z</published>
                <id>https://example.com/entry1</id>
            </entry>
        </feed>';
        
        $result = FeedParser::parse('https://example.com/atom', $atomContent);
        
        $this->assertIsArray($result);
        $this->assertEquals('Test Atom Feed', $result['title']);
        $this->assertIsArray($result['items']);
        $this->assertGreaterThanOrEqual(1, count($result['items']));
    }

    public function testParseJSONFeed(): void
    {
        $jsonContent = json_encode([
            'version' => 'https://jsonfeed.org/version/1',
            'title' => 'Test JSON Feed',
            'description' => 'Test Description',
            'home_page_url' => 'https://example.com',
            'items' => [
                [
                    'id' => '1',
                    'title' => 'Test Item',
                    'url' => 'https://example.com/item1',
                    'content_text' => 'Item content'
                ]
            ]
        ]);
        
        $result = FeedParser::parse('https://example.com/json', $jsonContent);
        
        $this->assertIsArray($result);
        $this->assertEquals('Test JSON Feed', $result['title']);
        $this->assertEquals('Test Description', $result['description']);
        $this->assertIsArray($result['items']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Test Item', $result['items'][0]['title']);
    }

    public function testParseThrowsExceptionForInvalidContent(): void
    {
        $invalidContent = 'This is not a valid feed';
        
        // FeedParser defaults to RSS and will throw an exception when trying to parse invalid XML
        $this->expectException(\Exception::class);
        
        FeedParser::parse('https://example.com/invalid', $invalidContent);
    }
}
