-- Migration: Create schema_migrations table
-- Description: Creates the schema_migrations table to track applied migrations
-- Version: 001
-- Date: 2025-09-08

-- Schema migrations table - tracks which migrations have been applied
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(50) PRIMARY KEY,
    description TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert initial migration record
INSERT INTO schema_migrations (version, description, applied_at) 
VALUES ('001', 'Create schema_migrations table', NOW())
ON CONFLICT (version) DO NOTHING;

-- Display completion message
SELECT 'Migration 001: schema_migrations table created successfully' AS result;
