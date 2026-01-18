# VibeReader

**Version:** 1.1.0

A PHP-based RSS reading platform similar to Google Reader. This application allows you to securely manage and read RSS, Atom, and JSON feeds with a clean three-pane interface.

## Features

### Core Functionality
- **Secure Authentication**: User registration and login with password hashing
- **Multi-Format Support**: RSS, Atom, and JSON Feed formats
- **Feed Discovery**: Automatically discovers feed URLs from website URLs (tries common paths and HTML `<link>` tags)
- **Three-Pane Interface**: 
  - Left pane: List of subscribed feeds organized in folders
  - Middle pane: Feed items
  - Right pane: Full article content with parsed HTML

### Feed Management
- **Add Feeds**: Add feeds by URL with automatic discovery
- **Delete Feeds**: Remove feeds from your subscription list
- **Manual Refresh**: Refresh individual feeds to get the latest posts
- **Auto-Refresh**: Automatically fetches latest posts for all feeds on login
- **Feed Reordering**: Drag and drop feeds to reorder them (order persists across sessions)
- **Folder Organization**: Organize feeds into custom folders
- **Folder Management**: Create, edit, delete, and reorder folders
- **Drag-and-Drop to Folders**: Drag feeds onto folder headers to organize them
- **OPML Export**: Export all your feeds and folders as an OPML file for backup or migration
- **OPML Import**: Import feeds from OPML files exported from other RSS readers (preserves folder structure)

### Reading Experience
- **Read Status Tracking**: Automatically marks items as read when viewed
- **Mark as Unread**: Mark previously read items as unread
- **Mark All as Read**: Quickly mark all items in a feed as read
- **Hide/Show Read Items**: Toggle visibility of read items (preference persists across sessions)
- **Unread Indicators**: Visual indicators for feeds and items with unread content
- **Bold Unread Items**: Unread items displayed in bolder typeface for easy identification

### Search
- **Full-Text Search**: Search across all feed items (title, content, summary, author)
- **Real-Time Results**: Live search with debouncing for performance
- **Search Results Display**: Shows feed name, date, and author for each result

### Customization & Preferences
- **Light/Dark Mode**: Toggle between light and dark themes
- **System Theme**: Automatically match system theme preference
- **Theme Persistence**: Theme preference saved across sessions
- **Timezone Settings**: Set your timezone for accurate date/time display
- **Font Selection**: Choose from multiple Google Fonts (Lato, Roboto, Noto Sans, Nunito, Mulish) or use system font
- **Italic Font Support**: Full font family support including italic faces

### User Interface
- **Modern Design**: Clean, responsive design with smooth transitions
- **Icon-Based Actions**: Intuitive icon buttons for common actions
- **Collapsible Folders**: Expand/collapse folders with persistent state
- **Responsive Layout**: Three-pane layout that adapts to screen size
- **Accessibility**: Proper ARIA labels and keyboard navigation support

### Technical
- **PostgreSQL Database**: Robust database for data storage (default in Docker; can fall back to SQLite for manual installation)
- **Database Migrations**: Automatic schema updates for new features
- **Session Management**: Secure session handling for authentication
- **Date/Time Formatting**: Timezone-aware date and time display using JavaScript `Intl.DateTimeFormat`

## Requirements

- PHP 8.0 or higher
- PDO with PostgreSQL support (for Docker) or SQLite support (for manual installation)
- cURL extension
- JSON extension
- SimpleXML extension
- libxml extension

## Installation

### Using Docker (Recommended)

1. Clone or download this repository

2. Build and start the container:
```bash
docker-compose up -d
```

3. Initialize the database:
```bash
docker-compose exec vibereader php scripts/setup.php
```

4. Access the application at `http://localhost:9999`

**Note**: The Docker setup uses PostgreSQL by default. Database credentials can be customized via environment variables in `docker-compose.yml`.

To stop the container:
```bash
docker-compose down
```

To view logs:
```bash
docker-compose logs -f
```

### Manual Installation

1. Clone or download this repository

2. Install dependencies using Composer:
```bash
composer install
```

3. Set up the database:
```bash
composer run setup
```
Or manually:
```bash
php scripts/setup.php
```

4. Configure your web server to point to the project directory. For development, you can use PHP's built-in server:
```bash
php -S localhost:8000
```

5. Access the application at `http://localhost:8000`

## Usage

1. **Register an Account**: Click "Register" on the login page to create a new account
2. **Login**: Use your credentials to log in
3. **Add Feeds**: Click the "+ Add Feed" button and enter a feed URL
4. **Read Articles**: 
   - Click on a feed in the left pane to see its items
   - Click on an item in the middle pane to read it in the right pane
   - Items are automatically marked as read when viewed

## Project Structure

```
php-vibe-reader/
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
├── data/
│   └── rss_reader.db (created on setup)
├── src/
│   ├── Controllers/
│   │   ├── ApiController.php
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   └── FeedController.php
│   ├── Auth.php
│   ├── Database.php
│   ├── FeedFetcher.php
│   ├── FeedParser.php
│   ├── Router.php
│   └── View.php
├── views/
│   ├── dashboard.php
│   ├── login.php
│   └── register.php
├── scripts/
│   └── setup.php
├── composer.json
├── Dockerfile
├── docker-compose.yml
├── index.php
└── README.md
```

## Database Schema

The application uses PostgreSQL (when running in Docker) or SQLite (for manual installation) with the following tables:

- **users**: User accounts with preferences (theme, timezone, font family, etc.)
- **folders**: Feed organization folders
- **feeds**: Subscribed feeds with folder assignments and sort order
- **feed_items**: Individual feed articles
- **read_items**: Tracks which items have been read by each user

## Future Enhancements

- Support for MySQL database (currently uses PostgreSQL in Docker)
- Feed refresh scheduling
- Keyboard shortcuts
- Mobile-responsive design improvements

## AI Development Notice

> **🤖 AI-Generated Project**
> 
> This project was developed entirely using AI-assisted coding through **Cursor IDE**. All features, code, and functionality were created through AI prompts and automated code generation. No manual coding was performed in the development of this application.
> 
> **Development Tool:**
> - [Cursor IDE](https://cursor.com/) - AI-powered code editor with integrated AI assistance
> 
> **AI Models Used:**
> - **Claude Sonnet 4.5** (Anthropic) - Primary model for code generation, architecture design, and feature implementation
> - **Claude Sonnet 3.5** (Anthropic) - Used for code completion and inline suggestions
> - **GPT-4** (OpenAI) - Used for code review, security auditing, and optimization suggestions
> 
> This notice is provided for transparency about the development process. The codebase demonstrates the capabilities of modern AI-assisted development tools in creating full-featured web applications with comprehensive security features, modern UI/UX, and production-ready code quality.

## License

This project is open source and available for use and modification.
