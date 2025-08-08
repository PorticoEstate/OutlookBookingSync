-- Add source event timing columns to bridge_mappings table
-- This allows deletion logic to only consider events within sync timeframes

ALTER TABLE bridge_mappings 
ADD COLUMN IF NOT EXISTS source_event_start VARCHAR(64),
ADD COLUMN IF NOT EXISTS source_event_end VARCHAR(64);

-- Create index for efficient deletion queries
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_source_timing 
ON bridge_mappings(source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_start);
