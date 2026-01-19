# API Documentation

Complete API reference for VibeReader's RESTful endpoints.

## Base URL

- **Development**: `http://localhost:9999`
- **Production**: Your domain URL

## Authentication

All API endpoints (except `/api/version`) require authentication via session cookies. The session is established through the web interface login.

### CSRF Protection

State-changing operations (POST, PUT, DELETE) require a CSRF token. Include it in one of these ways:

1. **Header** (recommended for AJAX):
   ```
   X-CSRF-Token: your-token-here
   ```

2. **POST body** (for form submissions):
   ```
   _token: your-token-here
   ```

3. **Query parameter** (GET requests):
   ```
   ?_token=your-token-here
   ```

Get the CSRF token from the HTML page (hidden input field) or via JavaScript:
```javascript
const token = document.querySelector('input[name="_token"]')?.value;
```

## Response Format

### Success Response

```json
{
  "success": true,
  "data": { ... },
  "message": "Optional message"
}
```

### Error Response

```json
{
  "success": false,
  "error": "Error message",
  "errors": ["Optional array of detailed errors"]
}
```

## Endpoints

### Feeds

#### GET /api/feeds

Get all feeds for the current user.

**Response:** Array of feed objects
```json
[
  {
    "id": 1,
    "title": "Example Feed",
    "url": "https://example.com/feed.xml",
    "feed_type": "rss",
    "item_count": 25,
    "unread_count": 5,
    "folder_id": 1,
    "folder_name": "News",
    "last_fetched": "2024-01-19T12:00:00Z"
  }
]
```

#### GET /api/feeds/{feedId}/items

Get items for a specific feed.

**Parameters:**
- `feedId` (path, integer) - Feed ID

**Response:** Array of feed item objects
```json
[
  {
    "id": 123,
    "feed_id": 1,
    "title": "Article Title",
    "link": "https://example.com/article",
    "content": "<p>Article content...</p>",
    "summary": "Article summary",
    "author": "Author Name",
    "published_at": "2024-01-19T10:00:00Z",
    "is_read": 0
  }
]
```

**Note:** Returns only unread items if user has "hide read items" preference enabled.

### Items

#### GET /api/items/{itemId}

Get a single feed item by ID.

**Parameters:**
- `itemId` (path, integer) - Item ID

**Response:** Feed item object with feed title
```json
{
  "id": 123,
  "feed_id": 1,
  "feed_title": "Example Feed",
  "title": "Article Title",
  "link": "https://example.com/article",
  "content": "<p>Article content...</p>",
  "summary": "Article summary",
  "author": "Author Name",
  "published_at": "2024-01-19T10:00:00Z",
  "is_read": 0
}
```

#### POST /api/items/{itemId}/read

Mark an item as read.

**Parameters:**
- `itemId` (path, integer) - Item ID

**Headers:**
- `X-CSRF-Token` (required)

**Response:**
```json
{
  "success": true
}
```

### Search

#### GET /api/search?q={query}

Search feed items across all user feeds.

**Query Parameters:**
- `q` (required, string) - Search query

**Response:** Array of matching feed items (up to 100 results)
```json
[
  {
    "id": 123,
    "feed_id": 1,
    "feed_title": "Example Feed",
    "title": "Article Title",
    "link": "https://example.com/article",
    "content": "Article content...",
    "summary": "Article summary",
    "author": "Author Name",
    "published_at": "2024-01-19T10:00:00Z",
    "is_read": 0
  }
]
```

**Searches in:** title, content, summary, author fields

### System

#### GET /api/version

Get application version information.

**Authentication:** Not required

**Response:**
```json
{
  "app_name": "VibeReader",
  "version": "1.1.1",
  "version_string": "VibeReader/1.1.1"
}
```

### Jobs

#### GET /api/jobs/stats

Get background job queue statistics.

**Response:**
```json
{
  "pending": 5,
  "processing": 1,
  "completed": 150,
  "failed": 2
}
```

#### POST /api/jobs/cleanup

Queue a cleanup job for feed items.

**Headers:**
- `X-CSRF-Token` (required)

**Body (application/x-www-form-urlencoded):**
- `feed_id` (optional, integer) - Specific feed ID to clean (null for all)
- `retention_days` (optional, integer) - Days to keep items
- `retention_count` (optional, integer) - Max items per feed

**Response:**
```json
{
  "success": true,
  "data": {
    "job_id": 456,
    "message": "Cleanup job queued"
  }
}
```

## Data Types

### Date Format

All dates are returned in ISO 8601 format with UTC timezone:
```
2024-01-19T12:00:00Z
```

### Read Status

Items use integer values for read status:
- `0` = Unread
- `1` = Read

## Error Codes

- `200` - Success
- `400` - Bad Request (invalid parameters)
- `401` - Unauthorized (authentication required)
- `403` - Forbidden (CSRF token invalid or insufficient permissions)
- `404` - Not Found
- `429` - Too Many Requests (rate limited)
- `500` - Internal Server Error

## OpenAPI Specification

A complete OpenAPI 3.0 specification is available in `openapi.yaml`.

### Viewing the API Documentation

You can use tools like:
- [Swagger UI](https://swagger.io/tools/swagger-ui/)
- [ReDoc](https://redocly.com/)
- [Postman](https://www.postman.com/) (import openapi.yaml)

### Example: Using Swagger UI

1. Install Swagger UI:
   ```bash
   npm install -g swagger-ui-serve
   ```

2. Serve the OpenAPI spec:
   ```bash
   swagger-ui-serve openapi.yaml
   ```

3. Open `http://localhost:3000` in your browser

## Examples

### JavaScript/Fetch

```javascript
// Get feeds
const feeds = await fetch('/api/feeds', {
  credentials: 'include' // Include session cookie
}).then(r => r.json());

// Mark item as read
const token = document.querySelector('input[name="_token"]').value;
await fetch('/api/items/123/read', {
  method: 'POST',
  headers: {
    'X-CSRF-Token': token
  },
  credentials: 'include'
});

// Search
const results = await fetch('/api/search?q=php', {
  credentials: 'include'
}).then(r => r.json());
```

### cURL

```bash
# Get feeds (with session cookie)
curl -b cookies.txt http://localhost:9999/api/feeds

# Mark item as read (with CSRF token)
curl -X POST \
  -H "X-CSRF-Token: your-token" \
  -b cookies.txt \
  http://localhost:9999/api/items/123/read

# Search
curl -b cookies.txt "http://localhost:9999/api/search?q=php"
```

## Rate Limiting

Some endpoints have rate limiting enabled:
- Login: 5 attempts per 15 minutes
- API endpoints: 100 requests per minute

Rate limit information is available in response headers (if implemented).

## Notes

- All endpoints return JSON
- Dates are in ISO 8601 UTC format
- Authentication is session-based (cookie)
- CSRF tokens are required for POST/PUT/DELETE operations
- Search is case-insensitive
- Item lists respect user preferences (hide read items, sort order)
