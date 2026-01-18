# Version Management

This project uses semantic versioning (MAJOR.MINOR.PATCH).

## Current Version: 1.0.0

## How to Update the Version

To update the version number, edit the `VERSION` constant in `src/Version.php`:

```php
public const VERSION = '1.0.0';  // Change this value
```

The version is automatically used in:
- **composer.json** - Package version
- **README.md** - Documentation header
- **HTML meta tags** - All view files (dashboard, login, register)
- **User-Agent header** - Feed fetcher requests
- **OPML exports** - Generated feed exports
- **API endpoint** - `/api/version` endpoint

## Version Format

Follow [Semantic Versioning](https://semver.org/):
- **MAJOR** version for incompatible API changes
- **MINOR** version for backwards-compatible functionality additions
- **PATCH** version for backwards-compatible bug fixes

## Version History

### 1.0.0 (Current)
- Initial release
- Core RSS/Atom/JSON feed reading functionality
- User authentication and feed management
- Folder organization
- OPML import/export
- Security features (CSRF protection, SSRF prevention, secure sessions)
