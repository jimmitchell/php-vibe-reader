# VibeReader

A PHP-based RSS reading platform similar to Google Reader. This application allows you to securely manage and read RSS, Atom, and JSON feeds with a clean three-pane interface.

## Features

- **Secure Authentication**: User registration and login with password hashing
- **Multi-Format Support**: RSS, Atom, and JSON Feed formats
- **Three-Pane Interface**: 
  - Left pane: List of subscribed feeds
  - Middle pane: Feed items
  - Right pane: Full article content
- **Read Status Tracking**: Automatically marks items as read when viewed
- **SQLite Database**: Lightweight database for data storage (easily extensible to MySQL/PostgreSQL)
- **Modern UI**: Clean, responsive design

## Requirements

- PHP 8.0 or higher
- PDO with SQLite support
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

4. Access the application at `http://localhost:8000`

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
php-rss/
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

The application uses SQLite with the following tables:

- **users**: User accounts
- **feeds**: Subscribed feeds
- **feed_items**: Individual feed articles
- **read_items**: Tracks which items have been read by each user

## Future Enhancements

- Support for MySQL and PostgreSQL databases
- Feed refresh scheduling
- Search functionality
- Categories/folders for feeds
- Keyboard shortcuts
- Mobile-responsive design improvements
- Export/import feed lists (OPML)

## License

This project is open source and available for use and modification.
