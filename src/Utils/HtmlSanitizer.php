<?php

namespace PhpRss\Utils;

use HTMLPurifier;
use HTMLPurifier_Config;
use PhpRss\Config;
use PhpRss\Logger;

/**
 * HTML sanitization utility.
 * 
 * Sanitizes HTML content to prevent XSS attacks while preserving
 * legitimate formatting. Uses HTMLPurifier for server-side sanitization.
 */
class HtmlSanitizer
{
    /**
     * @var HTMLPurifier|null Singleton instance of HTMLPurifier
     */
    private static ?HTMLPurifier $purifier = null;

    /**
     * Get or create HTMLPurifier instance.
     * 
     * @return HTMLPurifier HTMLPurifier instance
     */
    private static function getPurifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            
            // Allow common HTML tags and attributes for feed content
            $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,a[href|title|target],ul,ol,li,blockquote,pre,code,img[src|alt|width|height],h1,h2,h3,h4,h5,h6,div,span[style],table,thead,tbody,tr,td,th');
            
            // Allow style attribute (for inline styling from feeds)
            $config->set('CSS.AllowedProperties', 'color,background-color,font-size,font-weight,font-style,text-align,text-decoration,margin,padding,border');
            
            // Allow data URIs for images (some feeds use them)
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'data' => true, 'mailto' => true]);
            
            // Allow target attribute for links
            $config->set('HTML.TargetBlank', true);
            
            // Set cache directory (create if doesn't exist)
            $cacheDir = __DIR__ . '/../../var/htmlpurifier';
            if (! is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $config->set('Cache.SerializerPath', $cacheDir);
            
            // Disable cache in development for easier debugging
            if (Config::get('app.env') === 'development' || Config::get('app.debug', false)) {
                $config->set('Cache.DefinitionImpl', null);
            }
            
            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }

    /**
     * Sanitize HTML content.
     * 
     * Removes dangerous HTML while preserving legitimate formatting.
     * If sanitization is disabled, returns content as-is.
     * 
     * @param string|null $html HTML content to sanitize
     * @return string|null Sanitized HTML or null if input was null
     */
    public static function sanitize(?string $html): ?string
    {
        // Check if sanitization is enabled
        if (! Config::get('sanitization.enabled', true)) {
            return $html;
        }

        // Return null if input is null or empty
        if (empty($html)) {
            return $html;
        }

        try {
            $purifier = self::getPurifier();
            $sanitized = $purifier->purify($html);
            
            // Log if content was significantly modified (possible XSS attempt)
            if (strlen($sanitized) < strlen($html) * 0.5 && strlen($html) > 100) {
                Logger::warning('HTML sanitization removed significant content', [
                    'original_length' => strlen($html),
                    'sanitized_length' => strlen($sanitized),
                    'context' => 'html_sanitization',
                ]);
            }
            
            return $sanitized;
        } catch (\Exception $e) {
            Logger::exception($e, ['context' => 'html_sanitization']);
            
            // On error, escape HTML instead of returning unsafe content
            return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    /**
     * Sanitize plain text (escapes HTML entities).
     * 
     * Use this for fields that should never contain HTML (like titles, authors).
     * 
     * @param string|null $text Plain text to escape
     * @return string|null Escaped text or null if input was null
     */
    public static function sanitizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Reset the purifier instance (useful for testing).
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$purifier = null;
    }
}
