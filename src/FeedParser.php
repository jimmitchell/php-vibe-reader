<?php

namespace PhpRss;

use PhpRss\Utils\HtmlSanitizer;

/**
 * Feed parsing class for RSS, Atom, and JSON Feed formats.
 *
 * Detects feed type and parses feed content into a standardized array format
 * that the application can work with regardless of the original feed format.
 */
class FeedParser
{
    /**
     * Parse feed content into a standardized format.
     *
     * Automatically detects the feed type (RSS, Atom, or JSON Feed) and
     * delegates to the appropriate parser. Returns a standardized array
     * structure with feed metadata and items.
     *
     * @param string $url The feed URL (used as fallback for missing feed links)
     * @param string $content The raw feed content to parse
     * @return array Parsed feed data with 'title', 'description', 'link', and 'items' keys
     * @throws \Exception If the feed type is unsupported or parsing fails
     */
    public static function parse(string $url, string $content): array
    {
        // Try to detect feed type
        $feedType = self::detectFeedType($content);

        switch ($feedType) {
            case 'rss':
                return self::parseRSS($content, $url);
            case 'atom':
                return self::parseAtom($content, $url);
            case 'json':
                return self::parseJSON($content, $url);
            default:
                throw new \Exception("Unsupported feed type");
        }
    }

    /**
     * Detect the type of feed format from content.
     *
     * Checks for JSON Feed first (by validating JSON structure), then checks
     * for RSS (looks for '<rss' or '<rdf:RDF'), then Atom (looks for '<feed').
     * Defaults to RSS if no format is detected.
     *
     * @param string $content The raw feed content to analyze
     * @return string The detected feed type: 'rss', 'atom', or 'json'
     */
    public static function detectFeedType(string $content): string
    {
        // Check for JSON Feed (look for JSON structure with feed-like properties)
        $json = \PhpRss\Utils::safeJsonDecode($content, null, true);
        if ($json !== null && is_array($json)) {
            // JSON Feed spec: should have 'version' and 'items' or 'title' and 'items'
            if ((isset($json['version']) && (strpos($json['version'], 'jsonfeed.org') !== false || $json['version'] === '1'))
                || (isset($json['items']) && is_array($json['items']) && isset($json['title']))) {
                return 'json';
            }
        }

        // Check for RSS before Atom: RSS feeds can contain '<feed' inside item content (e.g. URLs,
        // HTML), which would otherwise trigger a false Atom detection.
        if (strpos($content, '<rss') !== false || strpos($content, '<rdf:RDF') !== false) {
            return 'rss';
        }

        // Check for Atom (root element <feed>). Do not match on the Atom namespace URI alone:
        // RSS 2.0 feeds often declare xmlns:atom for <atom:link> (e.g. rel="self").
        if (strpos($content, '<feed') !== false) {
            return 'atom';
        }

        // Default to RSS
        return 'rss';
    }

    /**
     * Parse an RSS feed into a standardized format.
     *
     * Handles RSS 2.0 and RSS 1.0 formats, including namespaced extensions
     * like content:encoded and dc:creator. Uses multiple methods (direct
     * access, XPath) to extract feed items for maximum compatibility.
     *
     * @param string $content The raw RSS XML content
     * @param string $url The feed URL (used as fallback for missing channel link)
     * @return array Parsed RSS feed with 'title', 'description', 'link', and 'items' keys
     * @throws \Exception If the RSS XML cannot be parsed
     */
    private static function parseRSS(string $content, string $url): array
    {
        libxml_use_internal_errors(true);

        // Register namespaces for content:encoded and other common namespaces
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMsg = "Failed to parse RSS feed";
            if (! empty($errors)) {
                $errorMsg .= ": " . $errors[0]->message;
            }

            throw new \Exception($errorMsg);
        }

        // Get namespaces - getNamespaces(true) returns prefixes as keys
        $namespaces = $xml->getNamespaces(true);

        // Also get all namespaces including default
        $allNamespaces = $xml->getDocNamespaces(true, true);

        // Find content and dc namespace URIs
        $contentNsUri = null;
        $dcNsUri = null;

        foreach ($allNamespaces as $prefix => $uri) {
            if ($uri === 'http://purl.org/rss/1.0/modules/content/' || $prefix === 'content') {
                $contentNsUri = $uri;
            }
            if ($uri === 'http://purl.org/dc/elements/1.1/' || $prefix === 'dc') {
                $dcNsUri = $uri;
            }
        }

        $feed = [
            'title' => HtmlSanitizer::sanitizeText((string)($xml->channel->title ?? 'Untitled Feed')),
            'description' => HtmlSanitizer::sanitize((string)($xml->channel->description ?? '')),
            'link' => (string)($xml->channel->link ?? $url),
            'items' => [],
        ];

        // Register namespaces for xpath queries
        if ($contentNsUri) {
            $xml->registerXPathNamespace('content', $contentNsUri);
        }
        if ($dcNsUri) {
            $xml->registerXPathNamespace('dc', $dcNsUri);
        }

        // Try multiple methods to get items
        $items = [];

        // Method 1: Standard channel->item access
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = $item;
            }
        }

        // Method 2: XPath if first method found nothing
        if (empty($items)) {
            $items = $xml->xpath('//item');
        }

        // Method 3: Try without namespace
        if (empty($items)) {
            $items = $xml->xpath('//channel/item');
        }

        // Parse all found items
        foreach ($items as $item) {
            try {
                $feed['items'][] = self::parseRSSItem($item, $contentNsUri, $dcNsUri);
            } catch (\Exception $e) {
                // If parsing fails for one item, log but continue
                \PhpRss\Logger::warning("Failed to parse RSS item", ['error' => $e->getMessage()]);

                // Try basic parsing without namespaces
                try {
                    $feed['items'][] = [
                        'title' => HtmlSanitizer::sanitizeText((string)($item->title ?? 'Untitled')),
                        'link' => (string)($item->link ?? ''),
                        'content' => HtmlSanitizer::sanitize((string)($item->description ?? '')),
                        'summary' => HtmlSanitizer::sanitize((string)($item->description ?? '')),
                        'author' => HtmlSanitizer::sanitizeText((string)($item->author ?? '')),
                        'published_at' => self::parseDate((string)($item->pubDate ?? '')),
                        'guid' => (string)($item->guid ?? $item->link ?? uniqid()),
                    ];
                } catch (\Exception $e2) {
                    // Skip this item if even basic parsing fails
                    \PhpRss\Logger::warning("Basic RSS item parsing also failed", ['error' => $e2->getMessage()]);
                }
            }
        }

        return $feed;
    }

    /**
     * Parse a single RSS item element.
     *
     * Extracts all relevant fields from an RSS item, including handling
     * namespaced elements like content:encoded and dc:creator. Tries
     * multiple methods (namespace children, XPath) for compatibility.
     *
     * @param \SimpleXMLElement $item The RSS item XML element
     * @param string|null $contentNsUri The content namespace URI (for content:encoded)
     * @param string|null $dcNsUri The Dublin Core namespace URI (for dc:creator)
     * @return array Parsed item with 'title', 'link', 'content', 'summary', 'author', 'published_at', 'guid'
     */
    private static function parseRSSItem($item, ?string $contentNsUri, ?string $dcNsUri): array
    {
        // Get content:encoded if available
        $contentEncoded = '';

        // Method 1: Try namespace children using URI directly
        if ($contentNsUri) {
            try {
                $contentNodes = $item->children($contentNsUri);
                if (isset($contentNodes->encoded)) {
                    $contentEncoded = (string)$contentNodes->encoded;
                }
            } catch (\Exception $e) {
                // Ignore and try next method
            }
        }

        // Method 2: Try xpath (most reliable for namespaced elements)
        if (empty($contentEncoded) && $contentNsUri) {
            try {
                $nodes = $item->xpath('.//content:encoded');
                if (! empty($nodes) && isset($nodes[0])) {
                    $contentEncoded = (string)$nodes[0];
                }
            } catch (\Exception $e) {
                // Ignore and use fallback
            }
        }

        // Fallback to description if no content:encoded
        if (empty($contentEncoded)) {
            $contentEncoded = (string)($item->description ?? '');
        }

        // Get author from dc:creator if available
        $author = '';

        // Method 1: Try namespace children
        if ($dcNsUri) {
            try {
                $dcNodes = $item->children($dcNsUri);
                if (isset($dcNodes->creator)) {
                    $author = (string)$dcNodes->creator;
                }
            } catch (\Exception $e) {
                // Ignore and try next method
            }
        }

        // Method 2: Try xpath
        if (empty($author) && $dcNsUri) {
            try {
                $nodes = $item->xpath('.//dc:creator');
                if (! empty($nodes) && isset($nodes[0])) {
                    $author = (string)$nodes[0];
                }
            } catch (\Exception $e) {
                // Ignore and use fallback
            }
        }

        if (empty($author)) {
            $author = (string)($item->author ?? '');
        }

        return [
            'title' => HtmlSanitizer::sanitizeText((string)($item->title ?? 'Untitled')),
            'link' => (string)($item->link ?? ''),
            'content' => HtmlSanitizer::sanitize($contentEncoded),
            'summary' => HtmlSanitizer::sanitize((string)($item->description ?? '')),
            'author' => HtmlSanitizer::sanitizeText($author),
            'published_at' => self::parseDate((string)($item->pubDate ?? '')),
            'guid' => (string)($item->guid ?? $item->link ?? uniqid()),
        ];
    }

    /**
     * Parse an Atom feed into a standardized format.
     *
     * Extracts feed metadata and entry elements from Atom XML format.
     * Handles Atom-specific elements like <entry>, <content>, and <link href>.
     *
     * @param string $content The raw Atom XML content
     * @param string $url The feed URL (used as fallback for missing feed link)
     * @return array Parsed Atom feed with 'title', 'description', 'link', and 'items' keys
     * @throws \Exception If the Atom XML cannot be parsed
     */
    private static function parseAtom(string $content, string $url): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            throw new \Exception("Failed to parse Atom feed");
        }

        $feed = [
            'title' => HtmlSanitizer::sanitizeText((string)($xml->title ?? 'Untitled Feed')),
            'description' => HtmlSanitizer::sanitize((string)($xml->subtitle ?? '')),
            'link' => (string)($xml->link['href'] ?? $url),
            'items' => [],
        ];

        foreach ($xml->entry as $entry) {
            $link = '';
            if (isset($entry->link)) {
                $link = (string)$entry->link['href'];
            }

            $feed['items'][] = [
                'title' => HtmlSanitizer::sanitizeText((string)($entry->title ?? 'Untitled')),
                'link' => $link,
                'content' => HtmlSanitizer::sanitize((string)($entry->content ?? $entry->summary ?? '')),
                'summary' => HtmlSanitizer::sanitize((string)($entry->summary ?? '')),
                'author' => HtmlSanitizer::sanitizeText((string)($entry->author->name ?? '')),
                'published_at' => self::parseDate((string)($entry->published ?? $entry->updated ?? '')),
                'guid' => (string)($entry->id ?? ($link ?: uniqid())),
            ];
        }

        return $feed;
    }

    /**
     * Parse a JSON Feed into a standardized format.
     *
     * Follows the JSON Feed specification (https://jsonfeed.org/) to extract
     * feed metadata and items. Handles both content_html and content_text fields.
     *
     * @param string $content The raw JSON Feed content
     * @param string $url The feed URL (used as fallback for missing home_page_url)
     * @return array Parsed JSON Feed with 'title', 'description', 'link', and 'items' keys
     * @throws \Exception If the JSON cannot be parsed
     */
    private static function parseJSON(string $content, string $url): array
    {
        $json = \PhpRss\Utils::safeJsonDecode($content, null, true);

        if ($json === null || ! is_array($json)) {
            $error = json_last_error_msg();
            throw new \Exception("Failed to parse JSON feed: " . ($error ?: 'Invalid JSON format'));
        }

        $feed = [
            'title' => HtmlSanitizer::sanitizeText($json['title'] ?? 'Untitled Feed'),
            'description' => HtmlSanitizer::sanitize($json['description'] ?? ''),
            'link' => $json['home_page_url'] ?? $url,
            'items' => [],
        ];

        foreach ($json['items'] ?? [] as $item) {
            $feed['items'][] = [
                'title' => HtmlSanitizer::sanitizeText($item['title'] ?? 'Untitled'),
                'link' => $item['url'] ?? '',
                'content' => HtmlSanitizer::sanitize($item['content_html'] ?? $item['content_text'] ?? ''),
                'summary' => HtmlSanitizer::sanitize($item['summary'] ?? ''),
                'author' => HtmlSanitizer::sanitizeText($item['author']['name'] ?? ''),
                'published_at' => self::parseDate($item['date_published'] ?? ''),
                'guid' => $item['id'] ?? $item['url'] ?? uniqid(),
            ];
        }

        return $feed;
    }

    /**
     * Parse a date string into a standardized database format.
     *
     * Converts various date formats (RFC 2822, ISO 8601, etc.) into
     * MySQL/SQLite compatible format (Y-m-d H:i:s). Returns null if
     * the date cannot be parsed.
     *
     * @param string $dateString The date string to parse (various formats accepted)
     * @return string|null Date in 'Y-m-d H:i:s' format, or null if parsing fails
     */
    private static function parseDate(string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
