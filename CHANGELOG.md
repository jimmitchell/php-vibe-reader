# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2024

### Added
- **Centralized Configuration Management**: New `Config` class for environment-based configuration with defaults
- **Structured Logging**: PSR-3 compliant logging system using Monolog with rotating file handlers
- **Service Layer**: `FeedService` class to centralize business logic and reduce code duplication
- **Standardized API Responses**: `Response` helper class for consistent JSON API responses
- **Rate Limiting Middleware**: Database-backed rate limiting with configurable limits for login and API endpoints
- **PHPUnit Test Infrastructure**: Comprehensive unit test suite with 24 tests covering core functionality
- **Environment Configuration**: `.env.example` template for easy configuration management

### Changed
- **API Response Format**: All API endpoints now use standardized `Response` helper methods
- **Logging System**: Migrated from `error_log()` to structured logging with context and log levels
- **Error Handling**: Improved exception logging with full context and stack traces
- **Code Organization**: Reduced code duplication by centralizing feed operations in `FeedService`
- **Configuration**: Application settings now managed through centralized `Config` class

### Improved
- **Code Quality**: Eliminated all direct `echo json_encode()` calls in favor of `Response` methods
- **Maintainability**: Consistent patterns for API responses and error handling throughout codebase
- **Debugging**: Enhanced logging with structured context for better troubleshooting
- **Test Coverage**: Added comprehensive unit tests for Config, Logger, FeedService, RateLimiter, and Response classes
- **Documentation**: Updated implementation status tracking

### Technical Details
- All controllers now use `Response::success()`, `Response::error()`, or `Response::json()` for API responses
- All logging now uses `Logger::debug()`, `Logger::info()`, `Logger::warning()`, `Logger::error()`, or `Logger::exception()`
- Rate limiting integrated into `AuthController` for login attempts
- Feed ownership verification centralized in `FeedService`
- Removed redundant `header('Content-Type: application/json')` calls (handled by Response class)

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
