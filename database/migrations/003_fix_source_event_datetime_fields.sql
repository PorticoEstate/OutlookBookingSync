-- Migration: Fix source_event_start and source_event_end to use proper datetime types
-- Description: This migration converts VARCHAR datetime fields to TIMESTAMPTZ for better performance and data integrity
-- Version: 003
-- Date: 2025-09-11

-- Add new columns with proper datetime types (timezone-aware)
ALTER TABLE bridge_mappings 
ADD COLUMN source_event_start_new TIMESTAMPTZ,
ADD COLUMN source_event_end_new TIMESTAMPTZ;

-- Convert existing data, handling various datetime formats
UPDATE bridge_mappings 
SET 
    source_event_start_new = CASE 
        WHEN source_event_start IS NOT NULL AND source_event_start != '' THEN
            CASE 
                -- Handle ISO 8601 format with timezone (2023-12-25T10:30:00+00:00)
                WHEN source_event_start ~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$' THEN
                    source_event_start::TIMESTAMPTZ
                -- Handle ISO 8601 format without timezone (2023-12-25T10:30:00) - assume UTC
                WHEN source_event_start ~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$' THEN
                    (source_event_start || '+00:00')::TIMESTAMPTZ
                -- Handle standard format (2023-12-25 10:30:00) - assume UTC
                WHEN source_event_start ~ '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$' THEN
                    (source_event_start || '+00:00')::TIMESTAMPTZ
                -- Fallback: try to parse as generic timestamp and assume UTC
                ELSE
                    (source_event_start || '+00:00')::TIMESTAMPTZ
            END
        ELSE NULL
    END,
    source_event_end_new = CASE 
        WHEN source_event_end IS NOT NULL AND source_event_end != '' THEN
            CASE 
                -- Handle ISO 8601 format with timezone (2023-12-25T10:30:00+00:00)
                WHEN source_event_end ~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$' THEN
                    source_event_end::TIMESTAMPTZ
                -- Handle ISO 8601 format without timezone (2023-12-25T10:30:00) - assume UTC
                WHEN source_event_end ~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$' THEN
                    (source_event_end || '+00:00')::TIMESTAMPTZ
                -- Handle standard format (2023-12-25 10:30:00) - assume UTC
                WHEN source_event_end ~ '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$' THEN
                    (source_event_end || '+00:00')::TIMESTAMPTZ
                -- Fallback: try to parse as generic timestamp and assume UTC
                ELSE
                    (source_event_end || '+00:00')::TIMESTAMPTZ
            END
        ELSE NULL
    END;

-- Drop old columns and rename new ones
-- First, drop dependent views that reference the columns we're changing
DROP VIEW IF EXISTS v_active_bridge_mappings;

ALTER TABLE bridge_mappings 
DROP COLUMN source_event_start;

ALTER TABLE bridge_mappings 
DROP COLUMN source_event_end;

ALTER TABLE bridge_mappings 
RENAME COLUMN source_event_start_new TO source_event_start;

ALTER TABLE bridge_mappings 
RENAME COLUMN source_event_end_new TO source_event_end;

-- Recreate the view with the updated column structure
CREATE OR REPLACE VIEW v_active_bridge_mappings AS
SELECT 
    bm.*,
    CASE 
        WHEN bm.last_synced_at > NOW() - INTERVAL '1 hour' THEN 'recent'
        WHEN bm.last_synced_at > NOW() - INTERVAL '1 day' THEN 'daily'
        WHEN bm.last_synced_at > NOW() - INTERVAL '1 week' THEN 'weekly'
        ELSE 'stale'
    END as sync_freshness
FROM bridge_mappings bm;

-- Update the index to use the new timestamp fields for better performance
DROP INDEX IF EXISTS idx_bridge_mappings_pair_tenant_source_event_start;
CREATE INDEX idx_bridge_mappings_pair_tenant_source_event_start
    ON bridge_mappings (tenant_id, source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_start);

-- Add a specific index for time-range queries
CREATE INDEX idx_bridge_mappings_source_event_time_range 
    ON bridge_mappings (source_event_start, source_event_end) 
    WHERE source_event_start IS NOT NULL;

-- Update schema_migrations table
INSERT INTO schema_migrations (version, description, applied_at) 
VALUES ('003', 'Fix source_event_start and source_event_end to use TIMESTAMPTZ type', CURRENT_TIMESTAMP);
