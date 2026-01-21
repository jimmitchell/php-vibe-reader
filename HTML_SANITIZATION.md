# HTML Sanitization

VibeReader implements comprehensive HTML sanitization to prevent XSS (Cross-Site Scripting) attacks from malicious feed content.

## Overview

Feed content from RSS/Atom/JSON feeds can contain HTML, which could potentially include malicious scripts. This implementation sanitizes all feed content at multiple layers:

1. **Server-side sanitization** - HTMLPurifier sanitizes content before storing in database
2. **Client-side sanitization** - DOMPurify provides defense-in-depth when rendering content

## Server-Side Sanitization (HTMLPurifier)

### Implementation

- **Library**: `ezyang/htmlpurifier` (v4.16+)
- **Location**: `src/Utils/HtmlSanitizer.php`
- **Integration**: Automatically applied in `FeedParser` when parsing feeds

### What Gets Sanitized

- **Feed titles** - Plain text (HTML entities escaped)
- **Feed descriptions** - HTML sanitized
- **Item titles** - Plain text (HTML entities escaped)
- **Item content** - HTML sanitized (preserves formatting)
- **Item summaries** - HTML sanitized
- **Item authors** - Plain text (HTML entities escaped)

### Allowed HTML Tags

The sanitizer allows common formatting tags used in feed content:
- Text formatting: `p`, `br`, `strong`, `b`, `em`, `i`, `u`
- Links: `a[href|title|target]`
- Lists: `ul`, `ol`, `li`
- Code: `pre`, `code`
- Images: `img[src|alt|width|height]`
- Headings: `h1`, `h2`, `h3`, `h4`, `h5`, `h6`
- Structure: `div`, `span[style]`, `blockquote`
- Tables: `table`, `thead`, `tbody`, `tr`, `td`, `th`

### Allowed Attributes

- Links: `href`, `title`, `target`, `rel`
- Images: `src`, `alt`, `width`, `height`
- Styling: `style` (limited CSS properties)
- Allowed CSS properties: `color`, `background-color`, `font-size`, `font-weight`, `font-style`, `text-align`, `text-decoration`, `margin`, `padding`, `border`

### Configuration

Sanitization can be disabled via environment variable:

```bash
SANITIZATION_ENABLED=0  # Disable sanitization (not recommended)
```

**Default**: Enabled (`SANITIZATION_ENABLED=1`)

### Cache

HTMLPurifier uses a cache directory at `var/htmlpurifier/` to improve performance. This directory is automatically created and is excluded from Git.

## Client-Side Sanitization (DOMPurify)

### Implementation

- **Library**: DOMPurify v3.3.1 (via CDN)
- **Location**: Loaded in `views/dashboard.php`
- **Integration**: Applied in `assets/js/modules/items.js` when rendering item content

### Defense in Depth

Even though content is sanitized server-side, DOMPurify provides an additional layer of protection:
- Protects against any content that might bypass server-side sanitization
- Handles edge cases in browser rendering
- Provides real-time sanitization when content is displayed

### Configuration

DOMPurify uses the same allowed tags and attributes as the server-side sanitizer for consistency.

## Usage

### Server-Side

```php
use PhpRss\Utils\HtmlSanitizer;

// Sanitize HTML content (preserves formatting)
$cleanHtml = HtmlSanitizer::sanitize($feedContent);

// Sanitize plain text (escapes HTML entities)
$cleanText = HtmlSanitizer::sanitizeText($feedTitle);
```

### Client-Side

```javascript
// Sanitize HTML before setting innerHTML
const sanitized = DOMPurify.sanitize(content, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'table', 'thead', 'tbody', 'tr', 'td', 'th'],
    ALLOWED_ATTR: ['href', 'title', 'target', 'src', 'alt', 'width', 'height', 'style', 'rel'],
    ALLOW_DATA_ATTR: false
});

element.innerHTML = sanitized;
```

## Security Benefits

1. **Prevents Stored XSS** - Malicious scripts in feed content are removed before storage
2. **Prevents Reflected XSS** - Content is sanitized before being sent to the browser
3. **Defense in Depth** - Multiple layers of sanitization (server + client)
4. **Preserves Formatting** - Legitimate HTML formatting is maintained
5. **Configurable** - Can be disabled if needed (though not recommended)

## Performance

- **HTMLPurifier**: Uses caching to improve performance on repeated sanitization
- **DOMPurify**: Lightweight client-side library with minimal performance impact
- **Caching**: HTMLPurifier cache stored in `var/htmlpurifier/` (excluded from Git)

## Troubleshooting

### Content Appears Stripped

If legitimate content is being removed:
1. Check HTMLPurifier logs for warnings
2. Verify the content uses allowed tags/attributes
3. Review `src/Utils/HtmlSanitizer.php` configuration

### Sanitization Not Working

1. Verify `SANITIZATION_ENABLED=1` in environment
2. Check that HTMLPurifier is installed: `composer show ezyang/htmlpurifier`
3. Verify DOMPurify is loaded (check browser console)
4. Check that `var/htmlpurifier/` directory is writable

### Disabling Sanitization

**Not Recommended** - Only disable for debugging:

```bash
SANITIZATION_ENABLED=0
```

This will bypass server-side sanitization. Client-side DOMPurify will still sanitize content.

## Files Modified

- `src/Utils/HtmlSanitizer.php` (new) - HTML sanitization utility
- `src/FeedParser.php` - Integrated sanitization into all parsing methods
- `src/Config.php` - Added sanitization configuration
- `assets/js/modules/items.js` - Added DOMPurify client-side sanitization
- `views/dashboard.php` - Added DOMPurify CDN script
- `composer.json` - Added HTMLPurifier dependency
- `ENV_CONFIGURATION.md` - Added sanitization configuration documentation
- `.gitignore` - Added HTMLPurifier cache directory

## Testing

To test sanitization:

1. **Test with malicious content**:
   ```php
   $malicious = '<script>alert("XSS")</script><p>Safe content</p>';
   $sanitized = HtmlSanitizer::sanitize($malicious);
   // Result: '<p>Safe content</p>' (script removed)
   ```

2. **Test with legitimate HTML**:
   ```php
   $legitimate = '<p>This is <strong>bold</strong> text with a <a href="https://example.com">link</a>.</p>';
   $sanitized = HtmlSanitizer::sanitize($legitimate);
   // Result: Same content (preserved)
   ```

## References

- [HTMLPurifier Documentation](https://htmlpurifier.org/)
- [DOMPurify Documentation](https://github.com/cure53/DOMPurify)
- [OWASP XSS Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
