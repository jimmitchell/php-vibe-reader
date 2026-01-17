<?php

namespace PhpRss;

class FeedDiscovery
{
    /**
     * Discover feed URLs from a given URL
     * 
     * @param string $url The URL to discover feeds from
     * @return array Array of discovered feed URLs
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
     * Try common feed paths
     * 
     * @param string $baseUrl The base URL
     * @return array Array of potential feed URLs
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
     * Verify if a URL is actually a feed
     * 
     * @param string $url The URL to verify
     * @return bool True if it's a valid feed
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
     * Resolve a relative URL to an absolute URL
     * 
     * @param string $baseUrl The base URL
     * @param string $relativeUrl The relative URL
     * @return string|null The absolute URL or null if invalid
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
