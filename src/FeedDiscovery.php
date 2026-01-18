<?php

namespace PhpRss;

/**
 * Feed discovery class for finding RSS/Atom/JSON feeds from website URLs.
 * 
 * When users provide a website URL instead of a direct feed URL, this class
 * attempts to discover the feed by trying common feed paths and parsing
 * HTML link tags for feed references.
 */
class FeedDiscovery
{
    /**
     * Discover feed URLs from a given website URL.
     * 
     * Uses multiple strategies to find feeds:
     * 1. Checks if the URL already appears to be a feed path
     * 2. Tries common feed paths (/feed, /rss, /atom.xml, etc.)
     * 3. Fetches the HTML page and looks for <link rel="alternate"> tags
     * 
     * Returns the first valid feed found. Each feed entry includes 'url',
     * 'type' (MIME type or 'discovered'), and optionally 'title'.
     * 
     * @param string $url The website URL to discover feeds from
     * @return array Array of discovered feed entries, each with 'url', 'type', and optionally 'title'
     */
    public static function discover(string $url): array
    {
        $feeds = [];
        
        // First, check if URL already looks like a feed path and try common variations
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        
        // If URL already ends with feed-like path, try it directly first
        $feedSuffixes = ['/feed', '/feed/', '/rss', '/rss/', '/atom', '/atom.xml', '/feed.xml', '/rss.xml'];
        foreach ($feedSuffixes as $suffix) {
            if (str_ends_with($path, $suffix)) {
                // Try the URL as-is first
                if (self::verifyFeed($url)) {
                    return [[
                        'url' => $url,
                        'type' => 'discovered',
                        'title' => ''
                    ]];
                }
                break;
            }
        }
        
        // Try common paths first (faster than HTML parsing)
        $feeds = self::tryCommonPaths($url);
        if (!empty($feeds)) {
            return $feeds;
        }
        
        // If common paths don't work, try HTML discovery
        try {
            // Fetch the HTML page
            $html = FeedFetcher::fetch($url);
            
            // Check if it's actually a feed (maybe the URL was already a feed)
            try {
                FeedParser::parse($url, $html);
                return [[
                    'url' => $url,
                    'type' => 'discovered',
                    'title' => ''
                ]];
            } catch (\Exception $e) {
                // Not a feed, continue with HTML parsing
            }
            
            // Parse HTML to find feed links
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            
            // Look for link tags with feed types
            $xpath = new \DOMXPath($dom);
            $links = $xpath->query('//link[@rel="alternate"]');
            
            foreach ($links as $link) {
                $type = $link->getAttribute('type');
                $href = $link->getAttribute('href');
                
                // Check for RSS, Atom, or JSON feed types
                if (in_array($type, [
                    'application/rss+xml',
                    'application/atom+xml',
                    'application/feed+json',
                    'application/json',
                    'text/xml',
                    'application/xml'
                ]) && !empty($href)) {
                    // Convert relative URLs to absolute
                    $feedUrl = self::resolveUrl($url, $href);
                    if ($feedUrl && self::verifyFeed($feedUrl)) {
                        $feeds[] = [
                            'url' => $feedUrl,
                            'type' => $type,
                            'title' => $link->getAttribute('title') ?: ''
                        ];
                        // Return first valid feed found
                        return $feeds;
                    }
                }
            }
            
        } catch (\Exception $e) {
            // If HTML fetching fails, we already tried common paths above
        }
        
        return $feeds;
    }
    
    /**
     * Try common feed paths to find a valid feed URL.
     * 
     * Attempts various common feed path patterns like /feed, /rss, /atom.xml
     * at both the current page path and the root level. Also tries query
     * parameter variations like ?feed=rss.
     * 
     * @param string $baseUrl The base URL to try feed paths against
     * @return array Array of discovered feed entries (only returns first valid one found)
     */
    private static function tryCommonPaths(string $baseUrl): array
    {
        $feeds = [];
        $parsed = parse_url($baseUrl);
        $base = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }
        $path = rtrim($parsed['path'] ?? '', '/');
        
        // Check if URL already ends with a feed-like path
        $feedSuffixes = ['/feed', '/feed/', '/rss', '/rss/', '/atom', '/atom.xml', '/feed.xml', '/rss.xml'];
        $isFeedPath = false;
        foreach ($feedSuffixes as $suffix) {
            if (str_ends_with($path, $suffix)) {
                $isFeedPath = true;
                break;
            }
        }
        
        // If it already looks like a feed path, try it first
        if ($isFeedPath) {
            $feedUrl = $base . $path;
            if (self::verifyFeed($feedUrl)) {
                return [[
                    'url' => $feedUrl,
                    'type' => 'discovered',
                    'title' => ''
                ]];
            }
        }
        
        // Common feed paths to try
        $commonPaths = [
            '/feed',
            '/feed/',
            '/rss',
            '/rss/',
            '/atom',
            '/atom.xml',
            '/feed.xml',
            '/rss.xml',
            '/index.xml',
            '/feed.rss',
            '/feed.atom',
        ];
        
        // If path already ends with a feed suffix, don't append more
        if (!$isFeedPath) {
            // Try with current path (if path exists and isn't root)
            if ($path && $path !== '/') {
                foreach ($commonPaths as $feedPath) {
                    $feedUrl = $base . $path . $feedPath;
                    // Try to verify it's actually a feed
                    if (self::verifyFeed($feedUrl)) {
                        $feeds[] = [
                            'url' => $feedUrl,
                            'type' => 'discovered',
                            'title' => ''
                        ];
                        // Return first working feed
                        return $feeds;
                    }
                }
            }
        }
        
        // Also try at root level
        foreach ($commonPaths as $feedPath) {
            $feedUrl = $base . $feedPath;
            if (self::verifyFeed($feedUrl)) {
                $feeds[] = [
                    'url' => $feedUrl,
                    'type' => 'discovered',
                    'title' => ''
                ];
                return $feeds;
            }
        }
        
        // Try query parameters
        if (empty($feeds) && isset($parsed['path'])) {
            $queryPaths = [
                '/?feed=rss',
                '/?feed=rss2',
                '/?feed=atom',
            ];
            foreach ($queryPaths as $queryPath) {
                $feedUrl = $base . $parsed['path'] . $queryPath;
                if (self::verifyFeed($feedUrl)) {
                    $feeds[] = [
                        'url' => $feedUrl,
                        'type' => 'discovered',
                        'title' => ''
                    ];
                    break;
                }
            }
        }
        
        return $feeds;
    }
    
    /**
     * Verify if a URL points to a valid feed.
     * 
     * Fetches the content and attempts to parse it as a feed. Performs
     * basic validation (content length check) before attempting parsing.
     * 
     * @param string $url The URL to verify
     * @return bool True if the URL points to a valid feed, false otherwise
     */
    private static function verifyFeed(string $url): bool
    {
        try {
            $content = FeedFetcher::fetch($url);
            
            // Quick check: if content is very small or empty, probably not a feed
            if (strlen(trim($content)) < 100) {
                return false;
            }
            
            // Try to parse it as a feed
            FeedParser::parse($url, $content);
            return true;
        } catch (\Exception $e) {
            // Log for debugging but don't expose to user
            error_log("Feed verification failed for $url: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Resolve a relative URL to an absolute URL.
     * 
     * Handles both absolute paths (starting with /) and relative paths.
     * Normalizes the resulting URL by removing /./ and resolving ../ sequences.
     * 
     * @param string $baseUrl The base URL to resolve against
     * @param string $relativeUrl The relative URL to resolve
     * @return string|null The absolute URL, or null if the base URL is invalid
     */
    private static function resolveUrl(string $baseUrl, string $relativeUrl): ?string
    {
        // If already absolute, return as is
        if (filter_var($relativeUrl, FILTER_VALIDATE_URL)) {
            return $relativeUrl;
        }
        
        // Parse base URL
        $base = parse_url($baseUrl);
        if (!$base) {
            return null;
        }
        
        // If relative URL starts with /, it's absolute path
        if (strpos($relativeUrl, '/') === 0) {
            return $base['scheme'] . '://' . $base['host'] 
                . (isset($base['port']) ? ':' . $base['port'] : '') 
                . $relativeUrl;
        }
        
        // Relative path - combine with base path
        $basePath = isset($base['path']) ? dirname($base['path']) : '/';
        if ($basePath === '.') {
            $basePath = '/';
        }
        $basePath = rtrim($basePath, '/') . '/';
        
        $resolved = $base['scheme'] . '://' . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '')
            . $basePath . $relativeUrl;
        
        // Normalize the path
        $resolved = str_replace(['/./', '//'], '/', $resolved);
        $resolved = preg_replace('#/[^/]+/\.\./#', '/', $resolved);
        
        return $resolved;
    }
}
