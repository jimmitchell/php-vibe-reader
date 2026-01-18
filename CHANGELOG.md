# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024

### Added
- Initial release of VibeReader
- User authentication system with secure password hashing
- RSS, Atom, and JSON Feed format support
- Feed discovery from website URLs
- Three-pane interface (feeds, items, content)
- Feed management (add, delete, refresh)
- Folder organization for feeds
- OPML import/export functionality
- Read/unread status tracking
- Search functionality across all feeds
- User preferences (theme, timezone, font, sorting)
- CSRF protection
- SSRF protection for feed fetching
- Secure session management
- Security headers
- Comprehensive input validation

### Security
- CSRF token protection on all state-changing operations
- SSRF protection preventing access to internal/private IPs
- Secure session configuration (HttpOnly, Secure, SameSite)
- Password policy (minimum 8 characters)
- Input validation and sanitization
- File upload security (size limits, MIME type validation)
- Error handling that prevents information disclosure
