<?php

namespace PhpRss;

class FeedParser
{
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

    public static function detectFeedType(string $content): string
    {
        // Check for JSON Feed (look for JSON structure with feed-like properties)
        $json = json_decode($content, true);
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

    private static function parseRSS(string $content, string $url): array
    {
        libxml_use_internal_errors(true);
        
        // Register namespaces for content:encoded and other common namespaces
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMsg = "Failed to parse RSS feed";
            if (!empty($errors)) {
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
            'title' => (string)($xml->channel->title ?? 'Untitled Feed'),
            'description' => (string)($xml->channel->description ?? ''),
            'link' => (string)($xml->channel->link ?? $url),
            'items' => []
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
                error_log("Failed to parse RSS item: " . $e->getMessage());
                // Try basic parsing without namespaces
                try {
                    $feed['items'][] = [
                        'title' => (string)($item->title ?? 'Untitled'),
                        'link' => (string)($item->link ?? ''),
                        'content' => (string)($item->description ?? ''),
                        'summary' => (string)($item->description ?? ''),
                        'author' => (string)($item->author ?? ''),
                        'published_at' => self::parseDate((string)($item->pubDate ?? '')),
                        'guid' => (string)($item->guid ?? $item->link ?? uniqid())
                    ];
                } catch (\Exception $e2) {
                    // Skip this item if even basic parsing fails
                    error_log("Basic RSS item parsing also failed: " . $e2->getMessage());
                }
            }
        }

        return $feed;
    }

    /**
     * Parse a single RSS item
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
                if (!empty($nodes) && isset($nodes[0])) {
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
                if (!empty($nodes) && isset($nodes[0])) {
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
            'title' => (string)($item->title ?? 'Untitled'),
            'link' => (string)($item->link ?? ''),
            'content' => $contentEncoded,
            'summary' => (string)($item->description ?? ''),
            'author' => $author,
            'published_at' => self::parseDate((string)($item->pubDate ?? '')),
            'guid' => (string)($item->guid ?? $item->link ?? uniqid())
        ];
    }

    private static function parseAtom(string $content, string $url): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            throw new \Exception("Failed to parse Atom feed");
        }

        $feed = [
            'title' => (string)($xml->title ?? 'Untitled Feed'),
            'description' => (string)($xml->subtitle ?? ''),
            'link' => (string)($xml->link['href'] ?? $url),
            'items' => []
        ];

        foreach ($xml->entry as $entry) {
            $link = '';
            if (isset($entry->link)) {
                $link = (string)$entry->link['href'];
            }

            $feed['items'][] = [
                'title' => (string)($entry->title ?? 'Untitled'),
                'link' => $link,
                'content' => (string)($entry->content ?? $entry->summary ?? ''),
                'summary' => (string)($entry->summary ?? ''),
                'author' => (string)($entry->author->name ?? ''),
                'published_at' => self::parseDate((string)($entry->published ?? $entry->updated ?? '')),
                'guid' => (string)($entry->id ?? $link ?? uniqid())
            ];
        }

        return $feed;
    }

    private static function parseJSON(string $content, string $url): array
    {
        $json = json_decode($content, true);
        
        if ($json === null) {
            throw new \Exception("Failed to parse JSON feed");
        }

        $feed = [
            'title' => $json['title'] ?? 'Untitled Feed',
            'description' => $json['description'] ?? '',
            'link' => $json['home_page_url'] ?? $url,
            'items' => []
        ];

        foreach ($json['items'] ?? [] as $item) {
            $feed['items'][] = [
                'title' => $item['title'] ?? 'Untitled',
                'link' => $item['url'] ?? '',
                'content' => $item['content_html'] ?? $item['content_text'] ?? '',
                'summary' => $item['summary'] ?? '',
                'author' => $item['author']['name'] ?? '',
                'published_at' => self::parseDate($item['date_published'] ?? ''),
                'guid' => $item['id'] ?? $item['url'] ?? uniqid()
            ];
        }

        return $feed;
    }

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
