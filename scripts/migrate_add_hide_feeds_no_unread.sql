-- Migration script to add hide_feeds_with_no_unread column to users table
-- This script can be run manually if needed (for PostgreSQL)

-- For PostgreSQL:
ALTER TABLE users ADD COLUMN IF NOT EXISTS hide_feeds_with_no_unread INTEGER DEFAULT 0;

-- For SQLite (IF NOT EXISTS is not supported, so check first):
-- Run: php scripts/migrate_add_hide_feeds_no_unread.php instead
-- Or manually check if column exists before running:
-- ALTER TABLE users ADD COLUMN hide_feeds_with_no_unread INTEGER DEFAULT 0;