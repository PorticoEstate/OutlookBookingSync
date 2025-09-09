# Database Migrations

This directory contains SQL migration files for the OutlookBookingSync database schema.

## Web Interface

Use the **Web Migration Manager** at `/admin-migrations` for all migration operations:

1. **View Migration Status**: See which migrations are applied and pending
2. **Run Single Migration**: Execute individual migrations with dry-run option
3. **Run All Pending**: Execute all pending migrations at once
4. **Preview Migrations**: View SQL content before execution
5. **Create New Migrations**: Generate new migration files through the interface

## Migration Files

Migration files follow the naming convention: `XXX_description.sql`

- `XXX` - 3-digit version number (001, 002, etc.)
- `description` - Brief description using underscores

### Example Migration File

```sql
-- Migration: Add user preferences table
-- Description: Creates user_preferences table for storing user settings
-- Version: 003
-- Date: 2025-09-09

CREATE TABLE IF NOT EXISTS user_preferences (
    id SERIAL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    preferences JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Record migration completion
INSERT INTO schema_migrations (version, description, applied_at) 
VALUES ('003', 'Add user preferences table', NOW())
ON CONFLICT (version) DO NOTHING;

-- Display completion message
SELECT 'Migration 003: Add user preferences table completed successfully' AS result;
```

## Features

### Automatic Transaction Management
- Each migration runs in a database transaction
- Automatic rollback on errors
- Prevents partial schema changes

### Migration Tracking
- `schema_migrations` table tracks applied migrations
- Prevents duplicate execution
- Maintains migration history with timestamps

### Safety Features
- **Dry Run Mode**: Preview changes without executing
- **Version Validation**: Ensures migrations run in correct order
- **Duplicate Protection**: Prevents re-running applied migrations
- **Error Reporting**: Detailed error messages and rollback on failure

## Access

Navigate to `/admin-migrations` in your browser to access the migration management interface.

**Note**: Admin authentication may be required depending on your configuration.
