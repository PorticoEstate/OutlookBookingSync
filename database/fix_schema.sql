-- Fix the schema to allow reservation_id to be NULL for Outlook-originated events
ALTER TABLE outlook_calendar_mapping 
ALTER COLUMN reservation_id DROP NOT NULL;

-- Add index for performance
CREATE INDEX IF NOT EXISTS idx_outlook_event_id ON outlook_calendar_mapping(outlook_event_id);
CREATE INDEX IF NOT EXISTS idx_sync_direction ON outlook_calendar_mapping(sync_direction);
